# json_* performance baseline

Recorded before any parity work, so every later stage is graded against it.

- Compiler: `main` @ `c96dd0d`, cold `bin/build --seed` in this worktree.
- Host: macOS arm64, php 8.5.8.
- `REPS=5 MEM=1 bash bench/run.sh -k json` — best-of-5 wall, max RSS.
- ⚠ A fresh binary's FIRST exec pays XProtect (~670 ms); best-of-5 absorbs it.
- ⚠ An honest A/B is two binaries (`MANT=<other>/bin/manticore`), never a rebuild
  in place — the runtime bodies are `linkonce_odr`, so a per-case env var gives no
  control build.

| case | native(s) | php(s) | speedup | rss-n(MB) | rss-p(MB) |
|---|---|---|---|---|---|
| `json` | 0.07 | 0.13 | 1.9× | 1.8 | 25.0 |
| `json_records` | 0.32 | 0.54 | 1.7× | 17.5 | 31.1 |
| `json_utf8` | 0.06 | 0.16 | 2.7× | 6.1 | 27.3 |
| `json_decode` | 0.11 | 0.22 | 2.0× | 16.1 | 43.5 |
| `json_decode_records` | 0.06 | 0.15 | 2.5× | 10.7 | 36.8 |
| `json_escape_heavy` | 0.02 | 0.07 | 3.5× | 2.6 | 25.7 |
| `json_deep` | 0.02 | 0.10 | 5.0× | 1.9 | 25.4 |
| `json_objects` | — | — | **DIFF** | — | — |
| `json_pretty` | — | — | **DIFF** | — | — |
| `json_decode_object` | — | — | **DIFF** | — | — |

Speed is not the problem. **Parity is.** The harness refuses to time a case whose
output differs from php, and the three it refused are exactly the three features
this epic exists to build.

## After Stage 1a (flags honoured by the compiled-PHP encoder)

| case | speedup then → now | rss-n then → now |
|---|---|---|
| `json` | 1.9× → 2.1× | 1.8 → 1.9 |
| `json_records` | 1.7× → 1.8× | 17.5 → 10.9 |
| `json_decode` | 2.0× → 2.3× | 16.1 → 16.0 |
| `json_decode_records` | 2.5× → 2.7× | 10.7 → 10.7 |
| `json_utf8` | 2.7× → 2.5× | 6.1 → 4.4 |
| `json_escape_heavy` | 3.5× → 3.6× | 2.6 → 2.6 |
| `json_deep` | 5.0× → 5.3× | 1.9 → 1.9 |
| `json_pretty` | **DIFF → 0.8×** | — → **67.6** |

⚠ **Absolute wall times are NOT comparable between these two runs** — the second ran
on a loaded box and BOTH sides scaled ~3.5× (php's own `json` went 0.13 → 0.50). The
contention-resistant readings are the **ratio** and **max RSS**, and neither regressed.

`json_pretty` reaching parity is Stage 1a's whole point, and its numbers are the
honest cost of getting there: a flagged call runs the compiled-PHP encoder, which is
**slower than php and burns 2.5× its memory**. That is Stage 1b's target, not a
surprise.

## Stage 3: the error model exists — and what of it does NOT

Present and matching the oracle: `json_last_error()`, `json_last_error_msg()` (php's own
texts, taken from the oracle rather than paraphrased), `json_validate()`, all twelve
`JSON_ERROR_*` constants, `JSON_THROW_ON_ERROR` raising `JsonException` and leaving the
slot at NONE, `json_encode(): string|false`, INF/NAN failing with `JSON_ERROR_INF_OR_NAN`
instead of encoding as the number 0, and `$depth` failing the whole call on both sides
with `JSON_ERROR_DEPTH`.

**Stage 3(b): both decoders now REJECT rather than degrade.** `[1,2`, `[`, `{`, `{"a"`,
`{"a" 1}`, `[1,2]x`, `""` and whitespace-only input all answer null with
`JSON_ERROR_SYNTAX`, on the native path and the compiled-PHP one alike — the test drives
both, because `json_validate` and a non-literal `$assoc` take the PHP parser while
everything else takes the builtin, and the two must not disagree.

The error travels through the shared slot rather than being propagated out of the
recursion, so the whole change is six branches that **already existed** — every place the
decoder used to fall into `%adone`/`%odone` without consuming a closer is exactly an
end-of-input or wrong-byte condition. Cost is one call on the failure path and **zero on
the success path**.

Two things this got wrong first, both worth remembering:

  - **The container HEAD is a separate exit from its tail.** Fixing `%atoob`/`%otoob` (the
    tail) left `%aoob`/`%ooob` — input ending immediately after `[` or `{` — jumping
    straight to `%adone`. `[1,2` was rejected while `[` was not, and the first test set
    happened to contain only the former.
  - **The FIRST error must win.** `json_decode('[[[1]]]', true, 2)` hits the depth limit,
    aborts, and therefore leaves input unconsumed — so the trailing-garbage check
    relabelled `JSON_ERROR_DEPTH` as `JSON_ERROR_SYNTAX`. Fixed in the slot itself
    (`__mc_json_err` is sticky; only an explicit 0 resets), which closes the same hazard
    at all seven sites that set a code instead of requiring each to remember the order.

### ⛔ What validation still misses — measured, not assumed

| input | php | here |
|---|---|---|
| `[1,]` | null + SYNTAX | **`[1,0]`** — invents an element |
| `"a\x01b"` | null + CTRL_CHAR | **`"ab"`** — drops the byte |
| 600 levels of nesting | null + DEPTH | **decoded, err=0** |
| `"\x80"` | null + UTF8 | hands back the bad byte, err=0 |
| `[01]` | null + SYNTAX | `[1]` |

The first two are **worse than an under-report: they are wrong data with no signal.** A
trailing comma materialises a `0` element and a control character is silently deleted. Any
description of the remaining work as "we merely fail to report" is inaccurate — these two
corrupt.

The depth row is its own trap: the counter went into the compiled-PHP parser, but the gate
sends anything with a default `$depth` down the NATIVE path, which counts nothing. So the
common `json_decode($deep)` still runs to a stack overflow rather than
`JSON_ERROR_DEPTH` — the parameter works only where the parser is used. Encode has the
same shape.

Confirmed already matching: `JSON_PARTIAL_OUTPUT_ON_ERROR` (`[1,0]` with the slot at 7)
and int-overflow-to-float on decode.

Two mechanics worth keeping in mind when extending this:

  - The builtin REPLACES the whole call, so the PHP body never runs — anything php does
    on entry (clearing the slot) has to be emitted by the builtin too, or the slot keeps
    reporting a previous call's result.
  - `json_encode(): string|false` is a UNION, so its call sites expect a CELL. A builtin
    that hands back a raw pointer is a representation mismatch that nothing diagnoses.

## ⚠ `$depth` — FIXED in Stage 3; the divergence below is what it looked like

Stage 1a gave `json_encode` a `$depth` parameter and Stage 2 gave `json_decode` one.
For two commits neither matched php, and the parameter's presence made that easy to
miss — the signature advertised a feature that did nothing:

| | php | here |
|---|---|---|
| `json_encode($deep, 0, 2)` | `false` + `JSON_ERROR_DEPTH` | the over-deep node encodes as the string `null` |
| `json_decode($deep, true, 2)` | `null` + `JSON_ERROR_DEPTH` | decoded in full — the native decoder counts no depth at all |

Both are now php-exact: `json_encode` returns `string|false`, the PHP parser counts
CONTAINERS the way php does, and both set `JSON_ERROR_DEPTH`. Recorded here because the
shape is instructive — **adding a parameter and implementing it approximately is worse
than not having it**, since the signature says the feature works.

## Found while building Stage 2, NOT fixed: `get_debug_type` over a cell

```php
$o = json_decode('{"a":1}');
get_class($o);         // "stdClass"  — correct
$o instanceof stdClass // true        — correct
get_debug_type($o);    // "object"    — php says "stdClass"
```

The decode is right; the naming is not. `biGettype`'s CELL arm resolves the class
through the prelude `__mir_obj_type_name` instanceof chain, which has no arm for a
class it was not generated for and falls into a bare `"object"` — while
`get_class()` over the very same cell resolves off the class id and is exact. A
statically-typed receiver takes the `biGetClass` path and is also exact, which is
what makes the cell case easy to miss.

Deliberately **not** covered by a test case: a red test makes
`tools/selfhost_fixpoint.sh` exit before the MIR-golden and rebuild-stability
sub-gates, so a documentation-only failure would cost two gates. The fix belongs
with the class-descriptor work that also stops `json_encode` of an object
answering `{}` (`docs/ROADMAP.md`, per-class function pointers).

## What the three DIFFs actually are

| probe | php | manticore |
|---|---|---|
| `json_encode(new Row)` | `{"id":7,"n":"x"}` | `{}` |
| `json_encode((object)['a'=>1])` | `{"a":1}` | `{}` |
| `json_encode($v, JSON_PRETTY_PRINT)` | indented | **SIGSEGV** |
| `json_decode('{"a":1}')` | `object` | `array` |

The pretty-print row was recorded as "flag silently ignored" going in. It is not —
it is a crash, and the crash is not about the flag.

## The crash: surplus positional arguments emitted an invalid call

`json_encode($assoc, <anything>)` → rc 139, faulting in `__mir_rc_release_str` on
`0xfff4000100004618` (a *tagged string cell*, not a pointer). Bisected: a JSON list
survives, an assoc array does not; the same body compiled into a user module is
fine; a user function called with surplus arguments is fine. The IR names it:

```llvm
declare i64 @manticore_json_encode(i64)              ; one parameter
...
%r28 = call i64 @manticore_json_encode(i64 %r27, i64 0)   ; two arguments
%r29 = inttoptr i64 %r28 to ptr
call void @__mir_rc_release_str(ptr %r29)            ; %r28 is poison
```

`EmitLlvm::faCallArgs` already carried the invariant — its docblock says "Keeps the
emitted call matching its `declare`" — but enforced it **only for a `func_get_args`
callee**. Every other callee passed the surplus straight through. php accepts
surplus positional arguments on any call, so this was reachable from ordinary code
against any imported (`.sig`) function, not just json.

Fixed by making the invariant unconditional, with the surplus still **evaluated**
in source order and its value dropped (`surplusArgEffects`) so php's evaluation
semantics survive the trim. Regression test: `tests/aot/cases/call_surplus_args.php`.

## Second root: `(array)` lied about its KEY type

Minimising the crash walked out of json entirely:

```php
function e(mixed $v): string {
    $out = "";
    foreach ((array)$v as $k => $val) { $ks = is_string($k) ? $k : (string)$k; $out = $out . $ks; }
    return $out;
}
echo e($box["x"]);   // php: "a7"   manticore: SIGSEGV
```

`LowerFromAst::lowerCast` typed `(array)$x` as `assoc<string, cell>`. php's rule is
**per-kind and decided at runtime** — an object yields property names, a list yields
`0..n-1`, a scalar yields the single key `0` — which is why `EmitLlvmExpr::emitCast`
dispatches on the tag. The lowering claimed to know what the emitter deliberately
does not. Consequence in IR:

```llvm
%r76 = call i64 @__mir_array_key_at(...)   ; a key CELL
%r78 = icmp ne i64 1, 0                    ; is_string($k) FOLDED to true
tern.then.17:
  %r80 = inttoptr i64 %r79 to ptr          ; cell used as a raw pointer, no tag mask
  call void @__mir_rc_retain_str(ptr %r80) ; → SIGSEGV
```

The key is erased; the type now says so (`assoc<cell, cell>`). Regression test:
`tests/aot/cases/cast_array_key_kind.php`, all four per-kind cases against the oracle.

## Third root: `(array)$obj` on a CELL read the bag and nothing else

With the key type fixed, three of the four cast kinds matched php and `(array)$obj`
still faulted. The cell arm called `emitBagOfUnknownClass` — the **dynamic bag
only**. A class with declared properties and no bag has no arm in that switch, so it
fell to the default and loaded its **first declared slot** at stdClass's bag offset
as an assoc pointer: `public int $id` became a pointer and was retained.

`EmitLlvmBuiltins::emitObjectVarsByClassId` already exists for precisely this, and
its own docblock names the symptom — *"that is what made `get_object_vars()`,
`(array)$o` and `json_encode()` of such a value render `{}`"* — but the cast's cell
arm was never wired to it. Extracted its `$objPtr`-taking core as
`emitObjectVarsOfPtr` and pointed the cast at it (dropping the caller's now-double
retain, since that walk hands back an owned value on both arms).

## Fourth root: the class-id walk never merged the declared half with the bag

With the cast wired to the class-id walk, a class carrying **both** — an
`#[AllowDynamicProperties]` class that also declares properties — answered its
declared half and silently dropped every dynamic property. The per-class arm
emitted `emitDeclaredPropsArray` and stopped; the statically-typed arm of the same
cast has always finished with `@__mir_array_union(declared, bag)`. The two paths
disagreed about what `(array)$obj` means. The walk now unions, php's order
(declared first), which also fixes `get_object_vars()` over an erased receiver —
the other caller of that helper.

⚠ This closes the USER-module half only. Inside `lib/manticore_stdlib.o` the class
table is empty, so the same walk finds no holders and still answers the bag — which
is the `{}` that `json_encode` of an object returns. That half is Stage 4's
descriptor epic, unchanged.

## A further divergence the crash was hiding

`__mc_json_escape` exists twice with **different semantics**: the PHP body
(`src/Runtime/Json.php:35`) escapes `/` and `\u`-escapes every non-ASCII byte, per
php's default flags; the codegen builtin that shadows it (`EmitLlvmBuiltins::biJsonEscape`)
lets both pass through raw. So the compiled-PHP encoder — the one every 2-argument
call lands on — under-escapes exactly as if `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`
were always set. A builtin silently shadowing its own bootstrap body with weaker
semantics; Stage 1 closes it by making the flags real on both sides.

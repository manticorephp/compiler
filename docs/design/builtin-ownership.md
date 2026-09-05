# Handoff — the ownership contract for builtins, and what is left of it

**State**: merged into local `main` `f1baf57` (13 commits). The ownership table
is down to **3 leaks**, all of them one root — section 3, which is written for
someone picking it up cold and includes the attempt that failed and why.

Read this before touching rc/ownership code again. It starts from the model, so
a session with none of the context can start at the top.

---

## 1. The model, in one page

A **user function call** carries a complete ownership contract:

- the CALLER releases a fresh rc argument temp after the call
  (`EmitLlvm::freshRcArgFlavor` → `EmitLlvmCalls::emitCall`), and
- the CALLEE retains, on entry, whatever it keeps
  (`EmitLlvmMemory::initRcObjSlots`), and
- a RETURN is `+1`: `EmitLlvmModule::emitReturn` retains a borrowed payload —
  including a borrowed CELL payload — precisely so the caller may drop a result
  it discards.

A **codegen builtin** had none of the three. It reads the argument's buffer
inline and returns, so a fresh temp handed to one had no owner at all. Some
builtins looked correct only by accident: a `boxToCell` rebuild on the way in
frees the source as a side effect (`EmitLlvm::cellifySourceFlavor`), which is
why `implode` and `in_array` measured flat while `count` and `array_values`
leaked. **Accidental correctness is tomorrow's regression** — those names still
need converting even though they measure clean today.

Whether the caller MAY free a builtin's argument is a per-name question with
exactly three answers:

| class | the result … | safe when |
|---|---|---|
| **A** | cannot reference the argument — a scalar, or a string built byte by byte | immediately. `count`, `sizeof` |
| **B** | is a fresh ARRAY built by copying element WORDS with no retain, so it BORROWS the source's elements | the copy CO-OWNS. `array_keys`, `array_values` |
| **C** | IS an element or a key of the argument | the builtin hands back `+1` AND `EmitLlvm::isFreshCellTemp` names it, so a consumer gives that reference back. `max`, `min` over one array |

**The retain and the release are ONE change.** A retain without the matching
release is a leak; a release without the retain is a use-after-free that is
invisible under Zend, invisible at `-O0`, and often invisible until gen-2.
Never land one half.

### Where the machinery lives

- `EmitLlvmBuiltins::emitArrPtrArg(Node)` — emits a builtin's array operand AND
  registers it when it is a fresh owned temp.
- `EmitLlvmBuiltins::emitBuiltin` — a thin wrapper around `emitBuiltinDispatch`
  that marks the pending stack, then releases everything the dispatch
  registered. Nesting works: an inner builtin drains at its own exit.
- `EmitLlvm::builtinMintsOwnedArray(string)` — the NAME list of builtins whose
  ARRAY result is a fresh allocation. Consulted by `freshRcArgFlavor`, because
  `sigs->paramTypes` is evidence a user BODY was called and therefore answers
  "no" for every builtin. Absence is a leak; a wrong entry is a UAF.
- `EmitLlvm::isFreshCellTemp(Node)` — a cell result the caller owns.
- `EmitLlvm::$ptrArgCellByReg` — the cell temp of an `emitPtrArg` operand, keyed
  by the reg `freeStrTemp` will be handed. This is how ~30 string builtins got
  the cell drop with no per-site edit.

---

## 2. What is already done

`tools/ownership_probe.php` is the classifier the first version of this handoff
asked for, and it is now the instrument for the whole area:

    php tools/ownership_probe.php            # the table
    php tools/ownership_probe.php -k array_  # one name
    MANT=… SCALE=4 ITERS=… KEEP=1            # A/B another compiler, wider arm

One probe per builtin — `for (…) $acc += <consumer>(<builtin>(<fresh temp>))` —
RSS at 1x and 2x work (a third point at 4x separates a leak from a pool
plateau), and the same program diffed against `php`. **The ratio flags a MISSING
release, the parity flags an OVER-release**, and it exits non-zero on either, so
it is ready to become the gate Step 2 below still asks for.

The table went **20 leaks → 3**. Every root it found, in the order they fell:

| root | commit | what it was |
|---|---|---|
| a CAST consumed a fresh cell temp with no owner | `a1d7879` | `strlen(json_encode($v))` was flat, `strlen((string)json_encode($v))` leaked the whole document. Three arms in one site: the cell temp had no owner, the cast RESULT had no owner (`isFreshStringTemp` named only int/float/cell operands while the erased dispatch takes the same retain), and the pass-through arm INHERITS its operand — that last one is the whole of `array_pop` / `array_shift`. |
| a string KEY in an array literal escapes | `1b1f3a9` | `function f(int $i): array { return ["k" . $i => …]; }` handed back a map whose every key read as the LAST one written: the key went to the ARENA and `arena_leave` reclaimed it. `KIND_STORE_ELEMENT` had always passed `true` there; `KIND_ARRAY_LIT` had not. **A wrong answer, not a leak.** |
| class C converted | `ea8880a` | `array_first` / `array_last` / `array_key_first` / `array_key_last` + the cursor family. All three halves at once: `cellEndpointRetain`, the operand through `emitArrPtrArg`, the name in `isFreshCellTemp`. `array_pop` / `array_shift` needed none of it — they REMOVE the element, so the reference already transfers. |
| a vec's key is INT keys, not NO keys | `9945898` | `@return string[]` lowers to a VEC over a hashed buffer, and foreach read each string key with the raw packed accessor. THREE gates dropped the disagreement in a row (the ternary join took the then-arm; `Type::unionWith` lifted a null key to STRING where two concrete channels are a CELL; `NarrowReturns` asked `isAssoc()`, which is string-key-only). |
| an ASSOC call result was never owned | `875ef56` | `freshRcArgFlavor` exempted it on a reading of `isBorrowedObjReturn` that had stopped being true. `array_flip` 22 MB, `array_combine` 105 MB. |
| a CLOSURE LITERAL argument | `1d17dde` | `array_filter($t, "strlen")` — lowering coerces the string callable to a closure, `emitClosure` allocates an env at rc 1, and nobody owned it. |
| a `\|` inside brackets is not a top-level union | `a4470e7` | the gate was a plain `strpos`, so `array<int,string\|null>` (str_getcsv, fgetcsv, every `array<K,V\|null>`) collapsed to a bare cell — the `.sig` then carried `mixed`, not `mixed[]`, **and a caller cannot own an erased word**. |
| an obj/string ALIAS was retained and never released | `1335a76` `c526192` `f1baf57` | `$s = $x;` leaked one reference per call — the shape half the stdlib opens with. The retain was in the emitter, the release nowhere. Its pass-through half (`(string)$s` is the same pointer, so the same alias) followed, and `Compile\Mir\AliasOwn` is now the ONE predicate both sides read. |

**Gates at `f1baf57`** (merged into local main): suite **1050/1052, failed 0** ·
difftest **MATCH 967 / DIFF 2** (`error_handler_basic`,
`trigger_deprecation_shape`, both pre-existing) · **LINUX arm64, cold seed +
full suite, 1050/1052 failed 0** · the binary is a **bit-for-bit fixpoint**
(gen 0 = gen 3 = gen 4 = gen 5 by SHA-256), which is why `selfhost_fixpoint.sh`
was skipped by choice. Not run: **amd64**.

---

## 3. THE ONE ROOT LEFT — a variadic pack owns its ARRAY elements

`array_merge` **62.6 MB**, `array_diff` **11.2**, `array_intersect` **11.2** are
the only rows left in the table. They share one root, and the fix is WRITTEN AND
REVERTED — read this before writing it again.

### The root

A variadic call packs its trailing arguments into ONE array literal
(`LowerFns::defaultFillArgs` → `new ArrayLit($packed, …)`), so `array_merge($a,
$b)` passes `vec[vec[…]]`. A literal OWNS its elements:
`EmitLlvmArrays::emitArrayLitValue` ADOPTS a fresh one and RETAINS a borrowed
one. Its release drops every element kind that has a flavor — `vecstr`,
`vecobj`, `veccell` — but an ARRAY element has none:
`EmitLlvm::discardReleaseFlavor` falls through to a plain `vec`, which is the
repr walk, and a literal stamps no ownership repr. So the buffer is freed and
everything inside it is stranded.

Reproduce without any builtin at all:

```php
function vpack(array ...$as): int { $n = 0; foreach ($as as $a) { $n += count($a); } return $n; }
for (…) { $acc += vpack(explode(",", $s . $r), ["z"]); }   // 23.1 -> 44.2 MB
function two(array $a, array $b): int { … }                 // flat — not the call, the PACK
$x = [explode(",", $s . $r), ["z"]];                        // 23.0 -> 44.2 — not the call at all
```

The last line matters: the same hole exists for a nested literal in a LOCAL, so
this is not a variadic bug, it is the array-element half of literal ownership.

### What was tried, and what it cost

`8ab002a` released each owned ARRAY element at the call site, alongside the
literal itself, with the element's OWN static flavor (which is what makes the
nested strings go too — the generic repr walk cannot, because a nested array is
not self-describing). **It took the table to LEAK on 0 and the whole filtered
suite stayed green.** Then generation TWO of the self-build SIGSEGVed in
`LowerFns::finishClosure`, reading a freed string's FNV seed
(`0xcbf29ce484222325`). Reverted in `d332eed`.

Narrowings that did NOT move it:

1. gate the release on the retain the literal actually took (`rcRetainByType`
   returns '' both for a fresh producer, which transfers, and for a borrowed
   vec/assoc alias, which it declines to co-own);
2. strings only;
3. forget the registered elements when a builtin dispatch is DISCARDED and its
   IR thrown away (a real hazard — `emitBuiltin` already does this for
   `$arrArgTempRegs` — but not this bug);
4. landing the ALIAS fix first and reapplying the pack on top of it: three clean
   generations, canary still dead.

### What the next session should know

- ★ **A compiler built with `MANTICORE_DEBUG_VERIFY=1` crashes with NO guard
  firing.** Both the string and the array release paths carry an `rc <= 0`
  abort, and neither fires — so this is **not a double-release of a tracked
  buffer**. Look instead for a release of a word that was never rc-managed, or
  for a register from IR that was discarded.
- Next instrument: `MANTICORE_ARR_RC_TRACE=1` on the COMPILER itself (per-buffer
  rc history, `[ARC]` lines), or `MANTICORE_CC_TRACE=1`. The crash input is
  tiny — `tests/aot/cases/closure_match_inlined.php` — so the trace is readable.
- The alternative design, not attempted: stamp `ARRAY_REPR_*` (ownership) on an
  array at every producer that owns its elements, so the generic
  `__mir_array_release` walk becomes recursive and the static flavor is not
  needed. `EmitLlvmArrays::erasedReprCode` already does this for the erased
  STORE path and carries the ⚠ that blocked it: `uasort` writes a sorted buffer
  back WITHOUT retaining, so a stamped source frees elements the result still
  points at. That has to be fixed first.

### How to test it — the part that is not optional

**A green suite on the generation that EMITS a change proves nothing.** Both
roots in this epic passed 1000+ cases on gen 1 and killed gen 2.

```bash
cp <clean>/bin/manticore bin/manticore          # a poisoned tree does NOT recover
rm -rf lib && cp -R <clean>/lib lib             # by reverting the source alone
bin/build && bin/build                          # gen 1, gen 2
./bin/manticore compile tests/aot/cases/closure_match_inlined.php -o /tmp/canary
```

That canary caught both. It is also the only honest bisect harness: reseed,
build twice, test — anything less attributes a gen-2 crash to the wrong commit,
which happened here (the alias fix was blamed for the pack's crash because the
two were in the tree together).

⚠ And **only the FULL suite** caught the alias fix's over-release: 28 filtered
families, 1000+ cases, were all green while `__mc_hosts_lookup_in` answered `''`
for every host after the first.

---

## 3b. The rest of the original plan, still open

- **Step 2 — make the leak table a GATE.** `LEAK on 0` should block a merge the
  way `failed 0` does. `tools/ownership_probe.php` already exits non-zero;
  wiring it into `bin/build --verify` / the fixpoint script is the whole job.
  Second, free detector: `MANTICORE_PROFILE=1` prints retain vs release per
  flavor and nothing reads it — assert the balance over the bench corpus.
- **Step 4 — finish `isFreshCellTemp`'s consumers.** Wired: call arguments,
  `echo`, the ~30 string builtins (through `$ptrArgCellByReg`) and now CASTS.
  NOT wired, each a one-cell-per-call leak: concat operands, comparison
  operands, array-literal elements, `store_element`, `return`.
- **Step 5 — the native json encoder for the flagged path.** `EmitLlvmBuiltins`
  takes `json_encode` natively only when `argIsDefaultInt($args, 1, 0)`, so ANY
  non-zero flag falls back to the compiled-PHP walker. `json_pretty`,
  `json_records` and `json_objects` are the slowest bench rows because of it.
- **A separate wrong answer, found and not chased:** `GenericType::parse` does
  not understand a parenthesized group, so `@return (string|null)[]` still draws
  the "bare `array`" analyzer warning even though the type now lowers correctly.
## 4. Traps this work paid for

- ⚠ **Two parallel string arrays, never one array of pairs.** A nested array
  element comes back ERASED, and concatenating that cell renders its raw word:
  `ptrtoint ptr 44565160096`, which is not even valid IR. It poisoned the binary
  and needed a reseed from main's compiler. This is why `emitCall` keeps
  `$rcArgRegs` / `$rcArgFlavs` parallel — now documented at `$arrArgTempRegs`.
- ⚠ **A failed `bin/build` poisons `bin/manticore` AND `lib/*.o`.** Recovery:
  `cp /path/to/main/bin/manticore bin/manticore`, `rm -rf lib && cp -R
  /path/to/main/lib lib`, then `bin/build` twice (the second so the new compiler
  builds itself).
- ⚠ **A user-code replica of a stdlib bug stays flat.** Both the property-slot
  veto and the arena accumulator reproduced only in the real callee: the arena
  verdict depends on escape analysis, and in a small replica the accumulator
  escapes. Patch a COPY of the real source instead —
  `MANTICORE_PRELUDE=<dir>` for the prelude, and for the stdlib the loop is
  **8 seconds**: edit `src/Runtime/*.php`, `bin/manticore build --libs-only`,
  recompile the probe.
- ⚠ **Only a REGISTER can be released.** A const-folded array literal is emitted
  as a global address literal; guard with `str_starts_with($reg, '%')`.
- The ~3 s Zend loop proves an emitter change with no rebuild:
  `MC_SRC=$PWD/src MANTICORE_PRELUDE=$PWD/prelude php -d xdebug.mode=off
  tools/compile_user_mir.php <x.php>` — it needs `MC_SRC`, and you read the
  `.ll`, not the MIR.
- A pipe eats the exit code: `bash tools/difftest.sh | tail` always reports 0.
  Redirect to a file.
- ⚠ **The Zend loop does NOT link the stdlib**, so a stdlib callee types as
  `unknown` there and `cell` in the real binary. A correct patch can look like a
  no-op in that dump — `json_encode`'s cast fix did, and was reverted once on
  the strength of it. For any type-GATED ownership decision, read the binary's
  IR (`--keep-ir`, which writes next to the `-o` path, not the source).
- ⚠ **A green suite on the generation that EMITS a change proves nothing.** Two
  changes in this epic passed 1000+ cases on gen 1 and SIGSEGVed gen 2. Reseed,
  `bin/build` twice, and compile `tests/aot/cases/closure_match_inlined.php` —
  that canary caught both, and it is the only honest bisect harness.
- ⚠ **A filtered sweep is not the suite.** 28 families and 1000+ cases were
  green while `__mc_hosts_lookup_in` answered `''` for every host after the
  first; only `bash tests/aot/run.sh -j 0` found it.
- ⚠ **`MANTICORE_DEBUG_VERIFY=1` can hide the bug it is meant to name.** The
  guards shift allocation, and one of these crashes stopped reproducing under
  them. A crash that survives verify with NO guard firing is not an rc<=0
  release of a tracked buffer — look elsewhere.

---

## 5. Open, not part of this epic

- `plausiblePtrIr` dereferences an unvalidated word at ~10 sites — a crash
  class, not a leak, and it blocks tagged arithmetic.
- `Compile\Mir\Effects` is one OBJECT PER NODE: 2.0M allocated, 0 freed. Make it
  an int bitmask, then `Parser\Ast\Span` (1.5M), then the AST itself, dead once
  MIR exists. The largest single number in the project.
- `count(array_keys($a))` still BUILDS the key array to count it — a fold to
  `count($a)`.
- The two standing difftest divergences.

# The live census: the compiler frees almost nothing, and why

## The instrument

The per-class census counted ALLOCATIONS only. An allocation census answers
"what does this churn"; only alloc MINUS free answers "what is still alive at
the peak" — the question that decides whether a peak is the cost of HOLDING the
program or the cost of NOT LETTING GO of it.

Added the free half: a second table, one shared bump body so the two halves
cannot drift, and the counter call in `__mir_drop_<id>` — already per class, so
the dense index is a compile-time constant and no id→index switch is needed.

⚠ A class with no rc property and no `__destruct` emits no drop body at all;
under the census that reads as `free = 0`, i.e. a leak for every such class. The
counter is now itself reason enough to emit the body.

Validated against a balanced probe: `alloc == free == tagged_reclaim`.

## What it says (ONE file compiled)

```
OBJECTS:  alloc   264 277   free      701   →  0.27% freed
ARRAYS:   alloc 1 391 724   reclaim 1 339 018 →   96% freed
```

Arrays are fine. **Objects are never freed.** Top live:

```
live=168 411  alloc=168 411  free=0   Lexer\Token       ← 64% of live objects
live= 66 692  alloc= 66 724  free=32  Compile\Mir\Type
live=  7 392  alloc=  7 392  free=0   Compile\Mir\Effects
live=  6 208  alloc=  6 210  free=2   Parser\Ast\Span
live=  2 722  alloc=  2 722  free=0   Compile\Mir\LoadLocal
```

The whole AST, every token and every type survive to process exit. That is the
8 GB front, and it explains the cold half of the footprint: allocated, written
once, never read again, never freed.

⚠ Not the arena: under `MANTICORE_MEMORY=rc` even fewer objects are freed
(488 vs 701). This is genuine retention.

## The root, in 30 lines

`Lexer` frees 13/13 and `Parser` 2/2 — the HOLDERS die. The tokens do not. The
chain is `new self((new Lexer())->scan($source), …)`, and the array is a call
TEMP, never a named local:

```php
$c = new Consumer((new Producer())->run(10));   // 24 000 alloc / 4 000 freed
```

Only `emitCall` (free functions) releases fresh rc arg temps. **The constructor
path collects `isFreshStringTemp` and nothing else** — so a fresh obj/vec/assoc
temp handed to a constructor is never released. Its base reference, and with it
one ref per element, is stranded forever.

Three references per token (the append's base, the borrowed-return retain, the
property store's retain) against two releases. Exactly one stranded, which is
why `free` is not "low" but ZERO.

## Two fixes, one landed and one gated

**Landed — the element-shared veto is now per callee.** `shareCallArgs` marked
EVERY array local passed as an argument, whatever the callee did with it. A
by-VALUE parameter of a known callee is retained on entry
(`initRcObjSlots`) — the callee CO-OWNS, it does not consume — so the caller
must keep its own element release. Narrowed, never removed: an unknown or
builtin callee, a by-REF parameter, a variadic tail and an out-of-signature
position all keep the veto. Suite 1031/1033, 0 failed.

**Gated (`MANTICORE_RC_CTOR_ARG=1`, default OFF) — releasing the ctor arg temp.**
It fixes both reproducers (24 000 / 24 000) and it is correct. It is off because
it EXPOSES an older bug it was masking.

## ⚠ What the fix exposed — the next root, isolated

```php
class Direct { function __construct(array $o) { $this->o = $o; } }            // ok
class Merged { function __construct(array $o) { $this->o = array_merge($this->o, $o); } }
```
```
php:    direct=direct   merged=merged
native: direct=direct   merged=            ← use-after-free
```

`array_merge` is plain PHP: `foreach ($arr as $k => $v) { $out[$k] = $v; }`. Its
arrays are bare `array` (deliberately — call-site element inference depends on
it), so the element channel is ERASED and `rcRetainByType` answers `''` for a
cell. **The merged array holds BORROWS.** While callers leaked their source
array this was invisible; freeing the temp correctly makes it fatal.

⚠ It also means the co-ownership proof must be checked at BOTH ends: the entry
retain uses the PARAMETER's own flavor, so a parameter declared `array` retains
in repr mode and co-owns nothing at element depth. A proof checked at one end is
not a proof — `coOwnedArgFlavor` now tests the callee's flavor too.

**Next step, precisely:** an erased element COPY must retain through
`retainCellPayload` — the same tag-dispatched discipline the bag store already
uses (and the same lesson as the `unser_object` fix earlier on this branch: a
retain discipline has ONE owner). With that in place `MANTICORE_RC_CTOR_ARG`
can default on, and the census should move for the first time.

---

# Update: the erased copy retain landed, and generics are already read

## `array_merge` fixed (a correctness bug, not a leak)

An ERASED element copy — value cell/unknown INTO a container whose element
channel is cell/unknown — emitted no retain at all, so the destination kept a
BORROW. Now it retains through the tag-dispatched `retainCellPayload`, the
mirror of the `__mir_cell_drop` the release walk already uses on that same
channel. It is SELF-GUARDED (no NaN tag, small payload, or missing
RC_TAG_MAGIC ⇒ no-op), so a raw slot is left alone rather than misread.

```
php:    direct=direct   merged=merged
before: direct=direct   merged=            ← use-after-free
after:  direct=direct   merged=merged
```

With it, `MANTICORE_RC_CTOR_ARG` is default ON: suite **1031 passed, 0 failed,
1033 total**, and both leak reproducers are balanced (24 000 / 24 000).

## ⚠ Docblock generics are ALREADY read by the compiler

`Analyze\DocType`'s own doc says it is "a faithful port of the compiler's own
`LowerTypes::docTagType`", and that function is applied to properties (`@var`),
parameters (`@param`, `@param-out`), returns (`@return`), locals, `@use` and
`@extends`. Native `array<K,V>` / `T[]` hints work too and are already used in
`src/` (`Session.php`, `Pcre.php`) and even EMITTED by `Sig.php`.

So the generics MECHANISM is not missing. What is missing is the annotations:

```
bin/manticore analyze src --only array.no-value-type   →   237 findings
```

Top files: `Main.php` 31, `LowerFromAst.php` 28, `EmitLlvmBuiltins.php` 21,
`EmitLlvmFiber.php` 14, `EmitLlvm.php` 14, `EmitLlvmObjects.php` 13.

Each one is a place where the type engine is handed `KIND_UNKNOWN` and every
ownership decision downstream — retain depth, drop flavor, the co-ownership
proof — has to fail safe, which means leak. Annotating them is a direct,
bounded attack on the erased channel, and it needs no compiler change.

## Where the token leak stands

Both token properties now take the element-owning drop:

```
CLASSDROP Lexer\Lexer::tokens    YES vecobjown
CLASSDROP Parser\Parser::tokens  YES vecobjown
```

and the constructor arg temp is released. **Yet the compiler still frees 0 of
168 705 `Lexer\Token`.** So at least one more holder exists that none of the
three reproducers models. The real chain is longer than they are: the ctor does
not store `$tokens`, it COPIES from it into `$filtered` (dropping DocComment
tokens) and stores that — so a token is referenced by the scan array, by
`$filtered`, and by `Parser::$tokens`.

That is the next hunt, and it starts from a working instrument rather than a
guess.

---

# The token leak, narrowed to one line — and two changes reverted for being no-ops

## A millisecond reproducer inside the compiler

```
census-built compiler, dump-ast on a 3-line file
[CLASS] idx=143 alloc=25 free=0        Lexer\Token
```

Lex + parse alone, 25 tokens, none freed. `Lexer\Lexer` is 1/1 and
`Parser\Parser` is 1/1 — the HOLDERS die. Reducing `Parser::parseSource` to
`(new Lexer())->scan($source);` with the result discarded still leaks all 25.

## ⚠ Every standalone probe of that shape is BALANCED

A faithful model — append into a property, return it, copy into `$filtered` in a
constructor, store that — is 24 000 / 24 000. So is the discarded-result form,
and so is the version with the Lexer's own `$this->tokens[$n-1]->kind` read-back.

That is the finding: **the cause is not the local shape, it is module-wide
analysis state.** The veto tables (`propRawBorrow`, `propElemBorrow`,
`propOwnElemVeto`, `elementSharedLocals`) are computed over the whole program, so
a probe can never reproduce them — the same pattern as `$hasBag`, the bare-name
borrow key and the site-wide by-ref veto earlier on this branch.

## The one site, named

```
MANTICORE_BORROW_TRACE=tokens bin/manticore build --apps-only
BORROW Lexer\Lexer::tokens <- node kind return line 100
```

One site in the whole codebase: `Lexer.php:100`, `return $this->tokens;`.

The exemption for exactly this shape exists — `Debug::$rcReturnOwns`, whose doc
says a RETURN already takes the reference. But it is gated on
`returnRetainsBorrow()`, and that function answers for **STRING and OBJ only**:

```php
if ($tk === Type::KIND_STRING) { return true; }
if ($tk !== Type::KIND_OBJ)    { return false; }   // ← an ARRAY return, always
```

So `return $this->tokens;` — an ARRAY property return, the shape of every
`getTokens()` / `all()` / `scan()` accessor — is never exempted and vetoes its
slot. That is the next thing to test, and it is a one-line hypothesis.

## Two changes reverted, and why

**Promoted-parameter `@var`.** The AST classes annotate promoted constructor
properties as `/** @var TraitAdaptation[] */` on the PARAMETER, and nothing read
it (`Param` had no `docComment` field at all). Wired it end to end — parser
captures it, `LowerClasses` falls back to it. Effect on real code: **283
element-owning class drops before, 283 after, zero new.** `LowerClasses` already
recovers those element types by usage inference. Reverted: a fix with no
measurable effect is not a fix, and it cost a field on a hot AST node.

**Owned-result element flavor.** A discarded call result releasing an
element-bearing array with the plain flavor looked like the leak. Probe with the
change: 22 000 / 22 000. Probe WITHOUT it: 22 000 / 22 000. Reverted for the same
reason.

⚠ Both were written before a failing test existed. The rule this branch keeps
re-learning: **get the red first, then fix it** — otherwise "it should help" and
"it helps" are indistinguishable.

## Also disproved this session

The plan "annotate the 237 bare arrays and the erased channel closes" is NOT
established. The promoted-parameter experiment is direct evidence against it:
where usage inference already recovers the element type, an annotation changes
nothing. The 237 should be attacked only where a measurement shows the channel
actually erases.

## Tested: the array-return veto was real, and it was not the leak

`returnRetainsBorrow` now mirrors `isBorrowedObjReturn` for arrays. The two had
genuinely disagreed — the emitter retains a borrowed ARRAY property read on
return (`$isArr = isVec() || isAssoc()`), the veto predicate answered only for
STRING and OBJ — and `storeLocalRetainsProp`'s own doc states what a
disagreement costs: "either leaks (harmless) or blesses a borrow as owned (a free
of a live value)".

Result: the `BORROW Lexer\Lexer::tokens <- node kind return` mark is GONE and the
overwrite now drops. Suite **1031 passed, 0 failed, 1033 total**.

⚠ But the leak is unchanged — `Lexer\Token` is still `alloc=25 free=0`. The only
counter that moved is `array_release_buf` 10 → 11. The gain is masked by a
SECOND veto on the same slot: `propElemBorrow`, set by `scan()`'s own
`$this->tokens[$n - 1]->kind` read-back, which makes the slot's release
`assocbuf` — buffer only, elements untouched.

Kept anyway, and deliberately: unlike the two reverted changes it repairs an
invariant the codebase states explicitly, and it is suite-green. It is simply not
the leak.

## Where to point the next instrument

Both class drops are `vecobjown`, so at rc → 0 the elements WOULD be returned.
They are not, which means **the token buffer never reaches rc 0**. That is now a
counting question about one buffer, not a predicate-reading question — every
predicate on this path has been read and two of them corrected.

The instrument that answers it is a per-BUFFER rc trace (retain/release site +
resulting rc for one allocation), which does not exist yet. Reading more
emitter gates will not answer it; three attempts here produced two no-ops and
one masked fix.

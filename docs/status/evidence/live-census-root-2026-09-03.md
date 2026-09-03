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

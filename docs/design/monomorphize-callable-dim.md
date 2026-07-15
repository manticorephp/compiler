# Monomorphize — the callable dimension (dynamic-callback specialization)

Status: DESIGN. Branch `mono-callback`, worktree `../manticore-mono`.
Extends `Monomorphize.php` (array dimension, shipped) with a **callable
dimension**. Prereq landed: the KNOWN-closure cellify milestone (`0b0fbc7`,
[[cell-array-closure-arg-misbox-2026-07-15]]).

## The bug this closes

`emitInvoke` cellifies a typed-array arg only when the callee is a KNOWN
closure whose param is erased. A `callable`-typed param is a DYNAMIC invoke —
no known callee, no param types, no cellify:

```php
usort($x, fn($a, $b) => $cmp($a["k"], $b["k"]));   // $cmp int-arith → mis-sort
```

Inside `usort`, `$cmp($arr[$l], $arr[$r])` invokes the callback. `$cmp`'s type
is bare `KIND_CLOSURE` (no class) → dynamic dispatch → the pair array handed to
the closure param `$a` is passed RAW (never cellified) → `$a["k"]` reads a
raw-but-cell-typed value → misboxes when passed on. The `<=>`/`strcmp` cases
tolerate it (tag-dispatch); `-`/`+` do not.

## The mechanism (why monomorphization fixes it)

The KNOWN/dynamic split in `emitInvoke` (EmitLlvmCalls.php:244,273) is purely a
STATIC-TYPE question:

```php
$fn    = $iv->callee->type->class ?? '';          // '' → dynamic
$known = $fn !== '' && isset($this->closureCaptures[$fn]);
```

A closure LITERAL is typed `Type::obj('__closure_N')` (LowerFns.php:343) — the
identity rides in `->class`. A `callable $cmp` param is typed bare
`Type::closure()` — no class. So: **specialize the callback-taker per concrete
closure argument, retyping its callable param from bare closure to
`obj<__closure_N>`.** After re-InferTypes, `$cmp`'s local carries the class →
the internal invoke is KNOWN → the milestone's param-type-gated cellify fires
on the array args. Same engine as the array dimension: clone, substitute a
concrete param type, re-type, repoint call sites.

## Part 1 — NodeClone supports `Closure_`

`cloneWith` calls `NodeClone::block`, which THROWS on `KIND_CLOSURE`
(NodeClone.php:167) → `cloneWith` returns null → the whole fn bails. Two fns we
must clone contain closure literals in their bodies (uasort's decorate, and any
user callback-taker that builds a closure), so add the arm:

```php
if ($k === Node::KIND_CLOSURE) {
    $x = self::asClosure($n);
    return new Closure_($x->id, self::nodes($x->captures), $n->type, $x->captureByRef);
}
```

`captureByRef` is a `bool[]` — copied by value, no clone. `captures` are
`LoadLocal`/`NullConst` nodes — `self::nodes` handles them.

**Shallow by design.** This clones the Closure_ NODE and reuses the same
underlying `__closure_N` FunctionDef (the node's `id` and `->class` are
unchanged). Two clones of the enclosing fn then reference ONE closure fn. That
is correct for Phase A (see scope) and is the boundary to Phase B.

Also relax `Monomorphize::bodyHasUnsupported`: today a body DEFINING a closure
is rejected (a real hazard when the clone would need its OWN closure fn — Phase
B). For Phase A, a body that defines a closure is clonable **iff** that closure
is not on a callable specialization path — but the simplest safe rule is: keep
rejecting `STATIC_LOCAL_DECL`/`YIELD`; allow `CLOSURE` only when the callable
dimension is what's driving the specialization (guarded, see Part 3). Start
conservative — allow CLOSURE unconditionally only after Phase A gates green;
until then a body with a closure def stays a non-candidate EXCEPT for the
explicit callback-taker set proven safe.

## Part 2 — the callable dimension

Extend `dimensions()` and `callKey()`:

- **`isCallableParam(Type $t)`** — `$t->kind === KIND_CLOSURE` (bare or
  `closureOf`). By-ref/variadic excluded (same as arrays).
- **`dimensions()`** — a param is a callable dimension when it is a callable
  param AND ≥1 call site passes a CONCRETE closure (arg type `KIND_OBJ` with a
  `->class` present in `module->closureCaptures`, i.e. a real `__closure_N`).
  Named-string callables (`'strcmp'`), FCC, and `[$o,'m']` are NOT concrete
  closures → they leave the site on the original (the `$cell`/dynamic entry).
- **`callKey()`** — token for a callable dim = the closure class, sanitized:
  `p{di}_cl_{sanitize(__closure_N)}`. A site whose callable arg is not a
  concrete closure yields `''` for that dim → the whole site stays on the
  original.
- **`typeToken()`** — add `KIND_CLOSURE` handling for completeness (bare →
  `cl`, though a bare-closure dim never keys since it's not concrete).

Mixed dimensions compose: a fn with both an erased array param and a callable
param keys on both, exactly as the array-only path already does over multiple
dims.

## Part 3 — `cloneWith` binds the concrete closure type

`cloneWith` already substitutes `$repCall->args[$idx]->type` for a dimension
param. For a callable dim the rep call's arg type IS `obj<__closure_N>`, so the
existing substitution line already does the right thing — the clone's `$cmp`
param becomes `obj<__closure_N>`. No special-casing needed beyond `dimensions`
electing the index.

Re-InferTypes (already run after each round) then seeds the clone's `$cmp` local
to `obj<__closure_N>`, and `$cmp(...)` inside resolves KNOWN. The milestone
cellify in `emitInvoke` fires with `$known=true` and the closure's real
(erased) param types.

## Worklist / fixpoint interaction

The existing worklist already re-runs until fixpoint. The callable dim rides it:

- Round 1: user `usort($x, __closure_M)` → `usort$mono$p1_cl___closure_M`, its
  `$cmp` = `obj<__closure_M>`. Direct case DONE — `$cmp($arr[l],$arr[r])` known,
  pair args cellified.
- A candidate is skipped once `$mono$` (concrete params) → convergence holds
  (unchanged).

## Scope: Phase A vs Phase B

**Phase A (this branch) — DIRECT callback-takers.** The closure is written at
the CALL SITE and passed to a callback-taker (`usort`/`array_map`/`array_filter`
/`array_reduce`/`uksort`, and user callback-takers). Monomorphize the
callback-taker only; the shared underlying `__closure_N` needs no freshening
because it is defined at the caller's scope where its captures are already
concretely typed. Fixes the milestone repro
`usort($x, fn($a,$b)=>$cmp($a["k"],$b["k"]))`.

**Phase B (deferred) — TRANSITIVE, closure built inside a generic.** `uasort`'s
decorate builds `fn($a,$b)=>$cmp($a["v"],$b["v"])` INSIDE the generic body and
captures the generic's own `$cmp`. Monomorphizing `uasort` per `$cmp` clones the
Closure_ node but SHARES one `__closure_M` fn — whose capture-param `$cmp` is
then typed by the UNION of all capture sites (the original bare-closure copy +
each clone's concrete copy) → conflict → stays dynamic. Fully fixing uasort
needs **freshening the underlying `__closure_N` FunctionDef per specialization**
(a new id + repointed Closure_->class), so each clone gets its own closure fn
with its own concrete capture types. That requires NodeClone (or the caller) to
REGISTER a fresh FunctionDef in the module — NodeClone is currently pure/static.
Defer until Phase A is proven; then decide whether to thread module access into
a closure-freshening clone or do it in Monomorphize after NodeClone returns.

uasort keeps its current KNOWN-LIMITATION note until Phase B.

## Risks (Monomorphize is high-risk — only the fixpoint catches errors)

1. **Retyping a callable param breaks the FCC / dynamic path.** Broadly
   retyping a bare callable to `obj<__closure_N>` on a site that ALSO reaches
   via a named string / FCC would break it. Mitigation: a site keys ONLY when
   the arg is a concrete `__closure_N`; every other callable site stays on the
   original name-addressable entry. INVARIANT preserved: one `$cell`/dynamic
   entry per fn (design doc §Reflection).
2. **Prelude specialization multiplies code.** Each distinct closure passed to
   `usort` mints a `usort$mono$…`. SPEC_CAP (8) already backstops; on overflow
   the fn stays unspecialized (dynamic, today's behaviour) — correct, slower.
3. **Shared-closure mis-typing (Phase B territory).** If Phase A accidentally
   lets a body with a closure DEFINITION specialize on a callable dim, the
   shared `__closure_N` capture types conflict. Part 1's conservative
   `bodyHasUnsupported` rule prevents this until Phase B lands the freshening.
4. **Self-host is the only real gate.** Zend seed can't exercise the native
   ABI. Validate SOURCE change via `bin/compile` first, then the full
   fixpoint+stability. A mis-typed clone SIGSEGVs the self-build (the milestone's
   un-gated attempt did exactly this).

## Gate (every step)

`bin/compile` (cold seed, validates source) → `tests/aot/run.sh` (full) →
`tools/difftest.sh` (php parity) → `tools/selfhost_fixpoint.sh` (fixpoint +
suite + stability). Never gate a revert; solve root causes. Add an AOT case:
`usort` with `fn($a,$b)=>$cmp($a["k"],$b["k"])` int-arith comparator → must
match php.

## Phase B ATTEMPTED — blocked by the representation-consistency root

Phase B was implemented (closure-fn freshening: NodeClone `Closure_`, per-clone
`__closure_N'` minting + repoint, deferred call-site repointing, and broadening
`isErasedArrayParam` to cell-element/bare-`array` so `uasort`'s `$arr` also
specializes). Result on `uasort($x, fn($a,$b)=>$cmp($a["v"],$b["v"]))` with an
int-arith `$cmp`:

- The comparator now ORDERS correctly — `uasort` specializes on BOTH dims
  (`uasort$mono$p0_assoc_str_int_p1_obj___closure_1`), the freshened
  `__closure_2` invokes `usort$mono$p1_obj___closure_2` which invokes it KNOWN
  and cellifies the pairs. Ordering: FIXED.
- But the VALUES print as raw NaN-box bits (`-4222124650659839` for `1`): the
  decorated pair's `"v"` round-trips as a BOXED cell, and `uasort`'s writeback
  `$arr = $new` assigns that cell-valued array into the caller's int-typed
  byref slot; the caller's `echo` reads it raw. This is the
  [[representation_consistency_root_2026_06_30]] /
  [[unknown-cell-soundness-epic-2026-07-08]] boxing-at-array-conversion root,
  NOT a monomorphization gap.
- The array-dim broadening also regressed `stdlib_array_extra` (bare-`array`
  params over-specializing).

Two hazards found + fixed along the way (worth keeping if Phase B resumes):
1. **Native type erasure** — deferring repoints via `[$call,$name]` tuples lost
   the `Call` static type; native `->function` resolved by the wrong offset →
   SIGSEGV in the self-build. Fixed with parallel `Call[]` / `string[]` arrays.
2. **Repoint-before-clone ordering** — a sibling (`usort`, defined first)
   repointed the shared original `uasort` body before `uasort` was cloned, so
   the clone inherited `usort$mono$__closure_0` while its freshened arg was
   `__closure_2`. Fixed by deferring ALL repoints to end-of-round.

**Decision:** ship Phase A; keep the freshening machinery DORMANT
(`bodyHasUnsupported` rejects closure-defining candidates so it never fires) and
`isErasedArrayParam` NARROW. Re-enable Phase B only after the
representation-consistency epic makes typed⇄cell array conversion boxing-exact
at assignment/return/writeback boundaries. `uasort` keeps its KNOWN-LIMITATION
note.

## Phase B ROOT — precise diagnosis (repr-array branch, 2026-07-15)

Re-enabled Phase B (freshening + cell-element/bare-`array` dim) and reproduced on
`uasort($data, fn($x,$y)=>$x-$y)`: **ordering now CORRECT, values print boxed**
(`box(1) = -4222124650659839`). Traced the exact boundary:

1. `$data`:assoc[string,int] → `uasort$mono$p0_assoc_str_int_p1` gets `$arr`:assoc[string,int].
2. `$pairs[] = ["k"=>$k, "v"=>$v]` — k:string ∪ v:int ⇒ pair is assoc[string,**cell**];
   storing the int `$v` into a cell slot BOXES it (correct — heterogeneous array
   needs cell storage).
3. usort sorts (correct). Undecorate `$new[$p["k"]] = $p["v"]` — `$p["v"]` reads
   the boxed cell; `$new` infers assoc[?,**cell**].
4. **`$arr = $new`** — assigns a cell-valued array into the concrete
   assoc[string,int] byref slot with NO representation conversion. The boxed
   values stay boxed.
5. Caller reads `$data` as assoc[string,int] (byref keeps its static type) → `echo`
   treats each value as a raw int → prints the box bits.

The inlined (non-uasort) form WORKS because there the value stays cell-typed to the
`echo`, which unboxes a cell. Only the byref writeback through a concrete-typed
param loses the tag-awareness.

### The fix (mirrors the existing box-back precedent)
There is already a plant→emit coercion pattern: `InferTypes::planMergeShadow`
plants a store whose NODE type ≠ VALUE type, and `emitStoreLocal`
(EmitLlvmLocals.php:388) boxes on that signal. Mirror it for arrays:

- **New runtime helper `emitCellArrayToTyped(Type $elem)`** — the reverse of
  `emitAssocToCellArrayUnified` (EmitLlvmBuiltins.php:372): walk the cell array,
  UNBOX each value per `$elem` kind, keys preserved, into a fresh typed array.
- **`emitStoreLocal` de-cellify branch** — store NODE type is a concrete-element
  array AND value type is a cell-element array ⇒ de-cellify.
- **`InferTypes::inferStoreLocal` plant** — a store to a byref param whose declared
  type is a concrete-element array, from a cell-element-array value, keeps the
  store node typed as the declared concrete array (the de-cellify signal) instead
  of overwriting to the value's cell type.

### The risk (why this is checkpoint-worthy, not a quick edit)
This lives in `InferTypes` + `emitStoreLocal` — the exact pass the
[[unknown-cell-soundness-epic-2026-07-08]] documents as repeatedly SIGSEGV-ing the
self-build when a producer/consumer flip is inconsistent. The compiler's own source
has hundreds of array assignments; a de-cellify that fires one slot too wide
destabilizes the fixpoint. Two scopes:
- **Narrow:** de-cellify ONLY at a monomorphized byref-array-param writeback
  (uasort's exact shape) — minimal blast radius, self-host-safe by construction,
  fixes uasort, leaves the general array cluster alone.
- **General:** de-cellify at any concrete-array ← cell-array store — the real
  representation-consistency fix, but the full self-host-risk surface.

## First action

Part 1 (NodeClone Closure_) + Part 2/3 (callable dim, guarded to concrete
closures). Prove the milestone repro compiles and matches php under `bin/compile`,
then run the full gate before touching uasort's limitation note.

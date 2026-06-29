# Type-System Redesign — handoff + design (2026-06-08)

> ## ✅ STATUS: RESOLVED (2026-06-10) — the bug class is gone
> - **Phase 1 (runtime kind-tagging) DONE** `fe60cd2` — empty-`[]` vec/assoc
>   UAF killed via assoc ptr-8 tagging.
> - **Phase 3 (unified PhpArray) DONE** — see `docs/bootstrap/16` (all stages
>   shipped) + the **KIND_VEC/KIND_ASSOC → KIND_ARRAY collapse** (`2fbf13c`):
>   there is now ONE static array kind, so the vec-or-assoc guess that caused
>   the corruption **cannot be made**. The triggering bug is structurally
>   impossible.
> - **Phase 2 (static-precision context-directed typing) — mostly MOOT.** Its
>   main motivation (empty-`[]` mistyping) is dissolved by Phases 1+3. Residual
>   element-type precision could still help niche cases but is low priority.
>
> This doc is historical reference. Current roadmap: memory
> `docs/ROADMAP.md`.

**START HERE for the type-system session.** Goal: kill the empty-array
vec/assoc confusion that produces a latent assoc UAF (and blocks the
confined-object-release leak fix), and harden the array side of the type
lattice so vec↔assoc mismatches can't corrupt memory.

This doc has three parts: (1) the triggering bug, fully root-caused;
(2) the current type system and its gaps; (3) a phased redesign with
concrete options. Pair with `[[obj_local_release_leak_blocked]]` (the bug
trail) and the existing `docs/bootstrap/14-header-tagging-design.md` (the
precedent we should imitate).

---

## 1. The triggering bug (root cause, fully understood)

A static `parent::staticMethod()` call makes `bin/manticore` do an
**`assoc_retain` on a value that is actually a vec**, reading the assoc rc
field at offset 24 OOB past a 16-byte vec → garbage → spurious
`rc <= 0 (freed)` → SIGABRT (under verify) / latent corruption (without).

Deterministic repro (FILE ARG — `.manticore.php` in cwd shadows stdin):
```
MANTICORE_DEBUG_VERIFY=1 bash bin/compile
MANTICORE_DEBUG_VERIFY=1 bin/manticore dump-llvm-mir tests/aot/cases/parent_static_call.php
# → [VERIFY] assoc_retain: rc <= 0 (retaining freed buffer)
```
`hello_world` / `class_basic` stay rc=0 — any redesign MUST keep them clean.

### The chain
- `EmitLlvm::initRcObjSlots(Node $body, array $paramNames = [])`. The store
  `$this->currentParamNames = $paramNames` is, at the value, **valType=unknown,
  propType=assoc**. `emitStoreProperty` falls back to the *property's* assoc
  type when the value type is erased (intentional, for co-ownership) → emits
  `__manticore_assoc_retain($paramNames)`.
- Emitting `__main` (`echo Child::label()`) the call `initRcObjSlots($body)`
  is default-filled (at LOWERING, `defaultFillArgs`) with the `[]` default.
- **`InferTypes::inferArrayLit` types EVERY empty `[]` as `vec(unknown)`**
  (the `if ($first) { $node->type = Type::vec(Type::unknown()); }` branch —
  context-blind). So the default `[]` is a `vec[unknown]` → `emitArrayLit`
  allocates a 16-byte vec `[len@0, rc@8]`.
- `assoc_retain(vec)`: reads `cap` @ offset 8 = the vec's rc = 1 (≠0, skips
  the stub-skip path), then `rc` @ offset 24 = OOB → garbage ≤ 0 → abort.

### Why the obvious fixes fail (DEAD ENDS — do not retry)
- **empty `[]` → null in emitArrayLit:** UNSOUND. Some unknown-typed empty
  arrays are genuinely used as vecs; null corrupts them. Broke the verify
  build on *everything* (incl. hello_world).
- **`@param array<string,bool>` docblock on the param:** no effect — even if
  it retyped the param, the default `[]` VALUE is still emitted as a vec
  (its type is fixed at lowering, before InferTypes refines the param).
- **force-RC_HEAP / narrow obj-release:** just *reuses* the freed memory
  sooner, turning the latent UAF into a hard crash. Not the cause.

---

## 2. Current type system — shape and gaps

`src/Compile/Mir/Type.php` — a flat tagged lattice (`kind` + optional
`element`/`key`/`class`/`atoms`). Kinds: void, null, bool, int, float,
string, **vec**, **assoc**, obj, closure, **cell** (NaN-boxed tagged union),
**unknown**. Intentionally flat for the self-host pre-scan (deep trees blow
the budget).

### Gap A — empty-array literals are context-blind (THE root)
`inferArrayLit`: empty `[]` → `vec[unknown]`, ALWAYS. PHP `[]` is genuinely
ambiguous (list or map). The literal's type should be decided by its
*context* (the param / property / assignment it flows into), not fixed to vec.

### Gap B — `vec` and `assoc` are layout-incompatible runtime types
(`src/Compile/MemoryAbi.php`)
- vec header: `[len@0, rc@8]`, elements @16 (8B each). Tagged: obj/vec carry
  `RC_TAG_MAGIC` at ptr-8 (header-tagging `fb84157`).
- assoc header: `[len@0, cap@8, next_int@16, rc@24, nbuckets@32, buckets@40]`,
  entries @48 (24B each). **NOT** tagged at ptr-8.
A value typed one way but laid out the other ⇒ rc at the wrong offset ⇒
OOB read/write ⇒ corruption. There is currently **no runtime discriminator**
the assoc helpers can use to detect "this is actually a vec" (the obj/vec
tag exists, but assoc helpers don't consult it, and real assocs aren't
tagged so they can't naively read ptr-8).

### Gap C — bare `array` hint erases the element type → `unknown`
`isBareArrayHint` + `lowerTypeHint`: `array $x` with no `X[]`/`array<...>`
docblock → element erased. `emitStoreProperty` then *falls back to the
property's declared type* for the retain (the immediate trigger above).
Erased `array` is the seam where vec/assoc confusion leaks in.

### Gap D — `unionWith` is coarse
Different kinds → `unknown` (so `int|string` loses information; `cell` is the
escape hatch for heterogeneous maps). Fine for now, but a real union/refine
would remove a lot of `unknown` (and thus a lot of erased-type fallbacks).

### Gap E — assoc-vs-vec inference is FRAGILE
Touching the `scanAssoc*` heuristics "broke 56 tests" (see
`[[selfhost_pre_scan_flow_typing]]`). Any redesign must land behind
`tools/stability_check.sh 6` and the goldens, incrementally.

---

## 3. Redesign — phased, each independently shippable

Two axes: **(i) runtime safety** (a vec can never corrupt an assoc op even
if the static type is wrong) and **(ii) static precision** (the type matches
the runtime value, so the right helper is chosen). Do (i) first — it stops
the corruption cheaply and unblocks the obj-release leak work; (ii) is the
real fix and removes whole classes of `unknown`.

### Phase 1 — RUNTIME SAFETY via array kind-tagging (imitate `fb84157`)
The header-tagging fix killed the string↔obj/vec misroute by self-routing on
a tag at ptr-8. Do the same for vec↔assoc: give **both** vec and assoc a
1-word kind discriminator the rc/access helpers consult, so
`assoc_retain/release/set/get` on a vec (or vice-versa) **self-routes or
safely no-ops** instead of reading a wrong offset.
- Options for the discriminator: (a) extend the existing obj/vec ptr-8 tag
  scheme to assoc too (assoc gets a distinct magic at ptr-8), then each
  container helper checks ptr-8; (b) a kind byte in a spare header bit.
  (a) reuses proven machinery and a uniform `free(ptr-8)`.
- Minimum to kill THIS bug: `__manticore_assoc_retain` / `_release` detect a
  vec-tagged buffer at ptr-8 and treat it as "not an assoc" → no-op (the vec
  is rc-managed via vec helpers, or is an empty default that leaks one stub —
  acceptable). Bump `MemoryAbi::VERSION`.
- Gate: verify repro rc=0; hello_world/class_basic rc=0; run.sh + goldens +
  stability_check 6. This is the low-risk unblock — ship it alone.

### Phase 2 — STATIC PRECISION: context-directed array-literal typing
Make `[]` (and array literals generally) take the **expected type** from
their context instead of defaulting to vec.
- Add an "expected type" parameter threaded through `InferTypes` value
  positions (bidirectional / checking mode): a param default, a property
  store, an assignment to a typed local, an arg to a typed param, a typed
  return. An empty `[]` in an assoc-expected position → `assoc`; in a
  vec-expected position → `vec`; truly unconstrained → keep `vec[unknown]`
  (today's behaviour, but now rare).
- Fix `defaultFillArgs` (LowerFromAst) so a `[]` default is (re)typed by the
  param's RESOLVED type after InferTypes — the specific seam that bit us.
- Refine bare-`array` params to assoc from string-key usage (`$x[$strKey]`,
  `isset($x[$strKey])`) — removes the `unknown` that forces the
  emitStoreProperty assoc fallback. THIS is the fragile `scanAssoc*` area —
  land it in the smallest possible increments, goldens + stability each step.

### Phase 3 — NORTH STAR: one unified PHP-array type (Zend HashTable model)
The vec/assoc split is the source of the whole problem (two layouts, ambiguous
empty literal, lossy erasure). PHP has ONE array type — an ordered map that is
list-optimised when keys are packed ints. A single runtime `PhpArray`
(ordered hashtable with a packed-array fast path) and a single `KIND_ARRAY`
(element + key refinements as today) would:
- delete the empty-`[]` ambiguity (one representation, one empty value),
- delete the vec↔assoc rc-offset confusion (one header layout),
- match PHP semantics exactly (mixed int/string keys, order preservation).
Cost: a real runtime + codegen rewrite (the array hot paths: literal, append,
index get/set, foreach, count, the rc helpers, COW). Large; do it only after
Phases 1–2 prove the seams. It subsumes Stage 4 (vec COW) — COW becomes a
single array helper.

---

## Recommended path
1. **Phase 1 now** — array kind-tagging self-routing. Cheap, proven pattern,
   kills the corruption, unblocks the confined-object-release leak (which can
   then ship: see `[[obj_local_release_leak_blocked]]`).
2. **Phase 2** — context typing + `defaultFillArgs` retype + bare-array→assoc
   refinement. The correctness fix; do incrementally.
3. **Phase 3** — unified array, when ready for a big, well-gated rewrite.

## Invariants for every step
- Gate with `tools/stability_check.sh 6` + `tests/aot/run_mir_golden.sh` +
  `tests/aot/run.sh`. The assoc/vec inference is the most regression-prone
  code in the compiler (56-test blast radius); land minimal increments.
- Verify-build is the tool: `MANTICORE_DEBUG_VERIFY=1 bin/compile` then the
  repro above. A clean verify build on hello_world/class_basic is the
  no-regression signal; rc=0 on parent_static_call is the fix signal.
- Always debug with FILE ARGS, not stdin (the repo `.manticore.php` shadows
  stdin via `resolve_sources`).
- Header tagging (`docs/bootstrap/14`) is the template for Phase 1: tags are
  load-bearing and fail loud — a deterministic abort on a wrong access is a
  feature, not a bug.

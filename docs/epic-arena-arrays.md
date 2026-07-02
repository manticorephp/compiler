# EPIC — arena allocation for unified arrays (fewer allocations, hot ops)

**Goal:** non-escaping arrays bump-allocate in the arena and are bulk-freed at
scope exit — no `malloc`/rc/`free` per array. Systemic "fewer allocations" win:
arrays are the most common hot allocation; covers explode / array_map/filter /
array literals in loops. No string-ABI change. User scope decision (2026-07-01):
**ALL non-escaping arrays** — packed AND hashed, growing, nested (max win, max
UAF surface). Start FRESH (deep memory change; risk class = use-after-free /
silent corruption — the handoff's "100 GB leak from a tired deep change" trap).

## Why this is the right lever (verified 2026-07-01)
- `InferAllocKind` already computes escape soundly and ALREADY drives arena
  *strings* (NoRefcount = arena bump; RcHeap = malloc+rc). Escape sinks handled:
  return, throw, store-to-escaping-local, call-arg, alias-of-mutable-container
  (`$b=$a` forces source escape), string self-append. See InferAllocKind.php
  `traverse()` (~L112-215).
- The ONLY reason arrays are forced RcHeap today: **"Unified PhpArray has no
  arena path (every buffer is malloc'd)"** — InferAllocKind.php ~L125-139,
  `$uniArr = KIND_ARRAY_LIT` forced RC_HEAP. Confirmed: `__mir_array_alloc` →
  `malloc` (UnifiedArrayRuntime.php `emitAlloc` ~L501; `emitAllocTagged` ~L483;
  hashed bucket side-array malloc ~L203). Growth reallocs via `realloc`.
- Arena machinery EXISTS + is proven on strings: `__mir_arena_enter/leave`
  (per-frame, every `ret` runs leave), `__mir_arena_used`/`__mir_arena_restore`
  (per-iteration save/restore reset — already used for self-concat temps),
  `__mir_arena_alloc`, `__mir_arena_realloc`. See EmitLlvmRuntime.php ~L359-371,
  EmitLlvm.php ~L4387 (per-iter reset), ~L4951/5901 (enter/leave), ~L6450-6458
  (save/restore). String model: `__mir_str_alloc_arena` (EmitLlvmRuntime ~L144).

## Phases (gate HARD after each — fixpoint + difftest + stab, not just suite)
**P1 — arena array runtime (additive, INERT until P2/P3 wire it).**
- Add `__mir_array_alloc_arena(cap)` mirroring `emitAlloc` but base buffer from
  `__mir_arena_alloc` (via an `__mir_alloc_array_tagged_arena` that arena-allocs
  `size+8` and writes ARRAY_TAG_MAGIC). rc field: set to the ARENA sentinel used
  by strings (immortal / rc so retain/release no-op) — CHECK how arena strings
  mark rc so `__mir_array_retain`/`release` bail (tag-guarded on `ptr-8`; an arena
  array must NOT be freed by release_* and must NOT be rc-bumped). Simplest: give
  arena arrays a distinct tag at `ptr-8` (e.g. `ARRAY_TAG_ARENA`) so
  retain/release/`__mir_array_release_obj` bail immediately (bulk-freed by
  arena_leave instead).
- Growth: the grow paths (UnifiedArrayRuntime ~L915/983 `newcap=max(2×,4)`,
  realloc) must, for an arena array, `__mir_arena_realloc` (copy into a fresh
  arena bump, old one abandoned till reset) INSTEAD of `realloc` (which would
  free a non-heap ptr → crash). Detect arena-ness via the tag at `ptr-8`. Hashed
  promotion + bucket side-array (~L203 malloc) also need an arena variant when
  the array is arena.
- Gate: helpers exist, unused → suite unchanged.

**P2 — InferAllocKind: arrays become arena when non-escaping.**
- Remove the blanket `$uniArr` force-RcHeap; a KIND_ARRAY_LIT (and array-
  producing builtin result, e.g. explode) that does NOT escape → NO_REFCOUNT.
- KEEP RcHeap for: escaping (return/store-to-field/call-arg-that-retains),
  aliased (`$b=$a` already forces escape), and any array whose ELEMENTS are
  rc-heap containers it owns (nested: an arena outer holding rc-heap inner is
  fine; an rc-heap outer holding arena inner is NOT — the inner dies at reset
  while outer lives. RULE: an arena array's element arrays/objects must ALSO be
  arena or immortal, never rc-heap-owned. Enforce: if an array escapes, force its
  element-producing children to escape too — extend the existing child-escape
  propagation to array elements/values.)
- Objects stay RcHeap (emitNewObj has no arena path — out of scope).

**P3 — EmitLlvm + InsertMemoryOps wiring.**
- Array-literal / array-builtin alloc emits `_arena` variant when
  `allocKind === NO_REFCOUNT` (mirror the string alloc-kind switch, EmitLlvm
  ~L1528, ~L4875). Append/grow on an arena array routes to the arena grow path.
- `InsertMemoryOps`: no retain/release on NO_REFCOUNT arrays (mirror strings).
- `vecWriteBack` after a grow must store the possibly-relocated arena ptr back
  to the local (already does for rc arrays; verify arena ptr threads correctly).

## Soundness checklist (UAF = silent corruption; be paranoid)
- [ ] An arena array NEVER reaches a retain/release that frees or rc-bumps it
  (tag-guarded bail).
- [ ] An arena array that grows relocates via arena_realloc, never libc realloc/
  free (would corrupt the arena / crash).
- [ ] No rc-heap or long-lived value holds a pointer into an arena array past the
  reset (escape analysis must catch: return, field store, global, call-arg that
  retains, being an element of a longer-lived container).
- [ ] Nested: arena outer + arena inner OK; longer-lived outer + arena inner =
  BUG (force inner escape).
- [ ] Per-iteration reset (loop) must not reclaim an array that outlives the
  iteration (e.g. accumulated into a result). The self-append escape rule is the
  model — an accumulator escapes the back-edge.

## Validation (before trusting)
- Alloc-count probe: a hot loop building a throwaway array each iter (e.g.
  `for(...) { $t=[...]; use($t); }`) — confirm arena (no malloc growth; run at
  tiny N + inspect IR: `__mir_array_alloc_arena` present, no `__mir_array_release`).
- Perf: array / array_map / explode benches — expect fewer allocs → faster.
- Leak: macOS `ulimit -v` does NOT enforce — test at TINY N + inspect IR, then
  scale; watch RSS on a long loop (arena must reset per-iter, not grow unbounded).
- `bin/build --seed` if the binary is suspect; deep MM change → self-host divergence
  is likely — the fixpoint gate MUST pass (stage-2 builds + runs the suite).
- Make the Heisenbug deterministic: `DYLD_INSERT_LIBRARIES=/usr/lib/libgmalloc.dylib
  MALLOC_PROTECT_BEFORE=1` (from the union-rc-double-free session).

## Current baseline (start point)
HEAD `ed29a3f`, all green (363/363, difftest 354/0/0, stab 5×2). Perf: json 6× at
1.2MB, explode parity (native, no waste). Arrays malloc+rc everywhere.

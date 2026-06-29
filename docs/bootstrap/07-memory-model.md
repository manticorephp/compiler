# 07 — Memory Model

How Manticore manages PHP-value lifetimes in the binaries it emits.

**Status (2026-05-26):** Refcount layer landed across both assoc and
object headers, but currently runs in **partial mode** —
`new`-object ownership tracking is disabled and call-site retain/
release are stubbed under a "borrow" workaround. The model below
describes the *target*; subsequent commits walk back the workarounds
once the proper effect / escape analysis lands (B2 on the roadmap).

## Layers, shortest-lifetime first

1. **Compile-time FFI ownership (shipped).**
   `#[Ffi\Give]` / `#[Ffi\Take]` / `#[Ffi\Borrow]` on functions and
   parameters drive scope-bounded `free()` emission. The compiler
   tracks owning locals per function, releases on a `#[Take]`
   hand-off or `return $owning`, and emits the matching
   `call void @free(ptr X)` before each `return` and at the natural
   body end. Zero runtime cost beyond the literal `free` call.
   Detailed rules in the "Compile-time FFI ownership attributes"
   section.

2. **Assoc-array refcount + Copy-on-Write (partial, see 10).**
   `[k => v, …]` literals and their derived buffers (string-keyed
   arrays, mixed-key maps) live behind the 32-byte header layout
   defined in `12-memory-abi-contract.md`. `assoc_retain` and
   `assoc_release` walk the rc field on every alias-site write and
   scope-exit. `assoc_cow` clones on `rc > 1` write. The retain side
   currently fires at most assignment alias-sites (Variable,
   PropertyAccess, ArrayAccess) — `call-arg` and the corresponding
   callee param ownership are **disabled** under the borrow
   workaround. See [refcount-uaf-post-release-pattern](#) for the
   shared-pointer-across-frames issue that drove the workaround.

3. **Object refcount layer 1.5 (partial).**
   Non-`#[Struct]` class instances carry the 16-byte header
   (`class_id`, `rc_word`) and dispatch their destructor via the
   class-id switch on rc=0. Properties holding refcounted children
   release recursively. **Currently `objOwnedLocals` tracking is
   disabled** — `new`-results never enter the scope-exit release
   pass, so every `new` leaks until process exit. Acceptable for the
   short-lived CLI compiler today, blocks long-running daemons. The
   plan is to re-enable with cycle collector as safety net.

4. **Cycle GC (designed, parked).**
   Bacon-Rajan trial-deletion targeting refcounted-object cycles.
   Header byte 8 reserves color (2 bits used today, 7 bits in the
   contract) + buffered flag for the candidate-roots list; helpers
   `__manticore_cc_add_root` / `__manticore_cc_collect_cycles` plus
   the four walker functions exist in design (see
   `11-cycle-collector-design.md`) and a partial implementation was
   reverted on bug discovery. Resurrection waits on B2 (effect
   system) so the cycle walker only sees genuinely refcounted
   objects, not the borrow-side leaks.

5. **Per-`main()` arena (planned, not started).**
   For workloads that lean on transient strings / arrays without
   needing per-object refcount, the long-running design still calls
   for an `mmap` arena with bump allocation. Lower priority than
   finishing the refcount loop.

## Non-goals

- A refcount cell on every PHP value. The compiler emits direct
  LLVM types (i64, double, ptr) and bridges them at FFI / runtime
  call boundaries — no per-call alloc to wrap a scalar. Refcount
  only where a value can be aliased and the alias outlives the
  original.
- A "PHP-visible Arena scope API" (`\Manticore\Arena::scope(...)`).
  The arena is an implementation detail of `main()`, not a
  user-facing surface.
- A moving / compacting collector.
- Concurrent / incremental collection (we collect synchronously at
  safe points only).
- Generational collection (the arena gives most of the benefit
  cheaper, when we ship it).
- Cooperative tri-color marking from running code.
- **Tracing GC instead of refcount.** PHP semantics need
  deterministic destruction (`__destruct` ordering); tracing GC
  loses that. Decision is final.

## Diagnostic infrastructure (shipped, see 12 + 13)

- `MANTICORE_DEBUG_RC_TRACE=1` — emit `[OBJ]` / `[RC]` traces at
  every retain / release / free, with `site=...` tag for caller
  identification.
- `MANTICORE_DEBUG_VERIFY=1` — slow-path invariant checks (`rc >= 1`
  before dec, `class_id != 0` on retain/release, etc.). In
  production these are **lenient** — they skip the op rather than
  abort, so cascading corruption stops at the bad call.
- `MANTICORE_DEBUG_RC_POISON=1` — memset the buffer with `0xDE`
  before `free()` so use-after-free reads see a recognisable
  pattern.
- `MANTICORE_DEBUG_ESCAPE=1` — per-function audit dumping retain /
  release counts grouped by site category (`assign`, `scope-exit`,
  `methodcall-arg`, `prop-store`, etc.). Surfaces imbalance
  patterns at compile time.
- `MANTICORE_DEBUG_COMPILE_TRACE=1` — print the raw MemoryOp log
  per function.

`docs/bootstrap/12-memory-abi-contract.md` is the canonical layout
contract; do not change a header field without bumping
`MemoryAbi::VERSION`.

## Compile-time FFI ownership attributes

Direct-bound FFI functions (`#[Ffi\Library, Ffi\Symbol]`) lower to
a single LLVM `call` with no runtime indirection. The compiler
reads ownership intent off attributes and emits the right free /
refcount call at scope boundaries.

Attribute set (all in `Ffi\`):

| Attribute    | Target    | Meaning                                                                                       |
|--------------|-----------|-----------------------------------------------------------------------------------------------|
| `Borrow`     | parameter | Caller still owns the pointer; callee must not free. Default for any `Ptr`-typed parameter.   |
| `BorrowMut`  | parameter | Same as `Borrow` but the callee may write through the pointer. Advisory today.                |
| `Take`       | parameter | Callee takes ownership; will free at its own discretion. Caller drops its handle at the call. |
| `Give`       | function  | Function returns an owned ptr; caller must arrange to free it.                                |
| `StaticPtr`  | function  | Function returns a static/global ptr; nobody frees it (e.g. `getenv`).                        |
| `NoRefcount` | class     | Instances wrap raw external pointers (FFI handles); refcount layer skips them.                |

Lifetime emission:

```php
#[Library('c'), Symbol('strdup'), Give]
function strdup(string $s): Ptr {}

#[Library('c'), Symbol('free')]
function free(#[Take] Ptr $p): void {}

function uppercased(string $src): string {
    $copy = strdup($src);   // ← Give: compiler tracks this ptr in scope
    // ... transformations ...
    free($copy);            // ← Take: explicit handover; tracked-ptr list shrinks
    return $result;
}
```

When the compiler sees a `#[Give]`-returning call:

1. The returned ptr is recorded in a per-scope "owned locals" set
   keyed by the receiving local-variable name.
2. At every `return` and at the natural end of the function body,
   every still-live owned local in the set gets a
   `call void @free(ptr load $name)` emitted before the terminator.
3. A `#[Take]` parameter whose argument is a Variable removes that
   local from the owned set — the callee is now responsible.

Ownership move on plain assign:

- `$b = $a` where `$a` was owning transfers the obligation — `$a`
  is dropped from the set, `$b` is added. Single-owner move
  semantics: at any moment exactly one local in the function holds
  the obligation.

Returning an owning local:

- `return $owning` hands the obligation to the caller; the
  auto-free pass skips it. The caller's binding receives the
  marker if the caller assigns the result to a local on a `#[Give]`
  -returning call.

Loops / branching (deferred):

- Each control-flow merge needs a phi over the owned set. A ptr
  that is owned on one incoming edge but not the other gets joined
  as "owned with possible-null" — the synthesised free site would
  emit `if (p != null) free(p)`.
- For loops, ownership entering the loop must equal ownership
  leaving (otherwise auto-free is ambiguous). Today the compiler
  will under-free in those shapes; explicit `free($x)` inside the
  loop body is the workaround.

Storage to long-lived locations (deferred):

- Assigning a `#[Give]` result to an object property removes it
  from the owned set; the property hosts the lifetime now. Once
  object-level destructors are stable on the refcount layer, the
  property's class is responsible for the `free` in `__destruct`.

Out of scope (forever):

- Cycles between FFI handles — if a C library hands you cyclical
  pointers, you're in manual-lifetime territory.
- Cross-fiber transfer of owned pointers — needs the eventual
  fiber memory model.

## Refcount tier — what's actually in tree today

Triggered implicitly for any non-`#[Struct]` class without
`#[NoRefcount]`. The compiler emits `__manticore_obj_retain` /
`__manticore_obj_release` calls at alias sites and scope exits:

- `new Resource(...)` → header.rc = 1, header.color = BLACK,
  header.buffered = 0.
- `$b = $a` where `$a` is refcounted → obj_retain on the value.
- end of scope on a refcounted local → obj_release.
- rc_dec to zero → walk class-id switch, release ptr-typed
  properties, call `free(ptr)`. `__destruct` user-method dispatch
  not implemented yet.

**Currently disabled** (commented out, pending B2 effect system):

- `objOwnedLocals[$name] = true` on `new`-result assigns — see
  `Compile/Assignment.php`. Without this scope-exit doesn't release
  `new` results, so every `new` leaks until process exit.
- Caller-side `obj_retain` at `methodcall-arg-obj-*` /
  `staticcall-arg-obj-*` / `call-arg-obj-*` sites.
- Callee-side assoc / obj param ownership marker in
  `Compile/Compiler.php` (functions + methods).

Re-enabling these in the wrong order corrupts state (verified
empirically — see the commit chain ending `bc27f01` for the borrow-
model rationale and `b723261` for the post-borrow stabilisation).

`__destruct` interacts with `#[Ffi\Give]`/`#[Ffi\Take]`: a property
holding an owning ptr gets a free in the synthesised `__destruct`.
Not yet implemented because no PHP code in tree needs it.

## Array layout decision

PHP arrays are ordered maps of mixed-key (int + string) and
mixed-value content. A single uniform representation pays for the
worst case all the time. The compiler picks the cheaper layout when
compile-time analysis proves which kind of key is in use:

- **Vec layout** (`length` prefix + i64 slots): numerically-indexed
  sequences. Constant-time index, contiguous storage, no hash.
  Matches the existing `[v0, v1, v2]` literal surface. CoW for vec
  storage is the next assoc-side work item (Step F in
  `10-refcount-cow-design.md`).
- **Assoc layout** (32-byte hashmap header + 24-byte entries):
  string keys, sparse / non-sequential indexing, mixed types.
  Open-addressed. Currently lives behind a refcount field at offset
  24 (`MemoryAbi::ASSOC_RC_OFFSET`); CoW lands via the
  `__manticore_assoc_cow` helper on every mutating op.

The compiler walks the array's uses (assignments, reads, foreach)
and infers the appropriate shape. Mixed-mode arrays fall back to
assoc.

## Open questions

- Threshold for re-enabling `new`-result ownership tracking. Likely
  gated by the cycle collector landing — once a CC sweep runs,
  reckoning catches up even when scope-exit release leaks via the
  walker / closure shapes that drove the workaround.
- Whether to expose `gc_status()` / `gc_disable()` / `gc_enable()`
  for compatibility. Already stubs return sensible no-ops.
- `__destruct` ordering across nested release chains — needs
  iterative release worklist to bound stack depth, not the current
  recursive helper.
- Inline small-string optimisation for arena strings — bump-allocate
  the buffer at allocation site instead of via separate `malloc`.
  Pre-arena work item.

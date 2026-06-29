# 10 — Refcount + CoW Design & Alias-Site Audit

Production-grade memory model for Manticore. Foundation for Go/Rust-tier UX
and performance on PHP semantics.

## Goal

Predictable, deterministic memory management for compiled PHP. Match Zend's
refcount + CoW semantics so user code observes correct value-vs-reference
behaviour, with future escape analysis to elide most refcount ops for
non-escaping locals.

## Why refcount + CoW (not GC, not arenas, not Boehm)

- **PHP semantics natively refcount.** Zend, HHVM, and PHP-CPP all use
  refcount + CoW. Match the runtime model the language was designed for.
- **Predictable latency.** No stop-the-world pauses. Critical for HTTP
  servers, daemons, request-response paths.
- **AOT-friendly.** `inc/dec` of an `i64` is a single load + add + store
  in LLVM IR. Inlines trivially. No external GC runtime to link in.
- **Cycle handling is deferrable.** Cycles are rare in idiomatic PHP;
  Bacon-Rajan trial-deletion can land as a phase-2 add-on without
  disturbing the rest of the model.
- **NaN-boxing compatible.** Tag bits identify ptr-vs-scalar at runtime,
  so refcount ops can be guarded behind tag checks where the value type
  is dynamic.

Rejected alternatives:

- **Boehm conservative GC.** Wrong long-term: conservative scan blocks
  precise escape analysis, false roots leak memory, pauses unpredictable.
- **Pure arena per scope.** Wrong for PHP: arrays escape via
  `$obj->prop = $arr` and `return $arr` constantly. Promotion logic
  becomes a worse refcount.
- **Tracing GC (Go-style).** Possible long-term, but: heavy runtime,
  write barriers everywhere, doesn't compose with NaN-boxing without
  precise stack maps (significant compiler complexity), pauses still
  exist.

## Architecture layers

```
Layer 1: Refcount + CoW           ← foundation (this doc)
Layer 2: Cycle collector          ← phase-2, correctness for cycles
Layer 3: Escape analysis          ← biggest perf win; elides most refcount ops
Layer 4: Stack alloc for non-escaping arrays/objects
Layer 5: Optional nursery GC for very short-lived allocations
```

Each layer is independent; Layer 3+ are optimisations over a correct
Layer 1 baseline.

## Refcount semantics

Every assoc buffer (eventually every object instance) carries a refcount
slot in its header. Currently at byte offset 24 for assoc; objects will
follow the same convention when extended.

Three primitive operations:

- **`retain(ptr)`** — `rc += 1`. No-op on NULL. Called when a new owner
  is created (an additional slot points at the same buffer).
- **`release(ptr)`** — `rc -= 1`. Free + run destructor when `rc == 0`.
  No-op on NULL. Called when an owner is dropped (slot overwritten,
  scope exit, etc.).
- **`cow(ptr) -> ptr`** — clone-if-shared. Returns input unchanged when
  `rc <= 1` (single owner). On `rc > 1`, malloc + memcpy the buffer,
  set the clone's `rc = 1`, decrement the source's `rc`, return the
  clone. Called before in-place mutation.

CoW invariant: every mutating helper (`assoc_set`, `assoc_unset`, vec
append/store, etc.) calls `cow` on the buffer first. Mutations only
land on singly-owned buffers; aliases stay invariant.

## The two ownership transitions

Every site where a ptr value crosses a slot boundary falls into one of
two categories:

- **ALIAS** — a new owner is created alongside the existing one. Both
  slots now point at the same buffer. Requires **`retain`** at the
  store site.

- **TRANSFER** — the existing owner hands its reference to a new slot.
  No retain needed; the rc stays the same. Requires **`release`** on
  the source if the source slot is reused for something else.

In practice "transfer" only happens for `return $x` (callee's slot
dies, caller's slot inherits) and a few internal moves. Almost
everything else is alias.

## Scope-exit release rule

When a local owning an assoc buffer goes out of scope, the local
contributed `+1` to the rc; that must be released. Exit paths:

- Explicit `return` statement
- Fall-through to natural function-body end
- Explicit `throw` (rc must still drop)
- Catch handlers re-entering after a throw

All exit paths emit `release` for every owned local. `return $x`
**drops `$x` from the owned set first** so its reference transfers to
the caller — otherwise we'd over-release.

## Alias-site audit

Comprehensive map of every place a ptr crosses a slot boundary in the
PHP-side compiler. Each row notes file:line of the emit point, the PHP
source pattern, the category (ALIAS / TRANSFER / REF), and whether
retain/release is currently wired.

Legend:
- ✅ wired (Step D)
- ❌ missing — needs wiring
- — not applicable

### Variable-to-variable assignment

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `Assignment.php:382-391` | `$a = $b` | ALIAS | ✅ |

### Property store (target is `$obj->prop`)

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `Assignment.php:479` | `$obj->prop = $rhs` | ALIAS | ❌ |
| `Assignment.php:559` | `$obj->prop[] = $v` (assoc-mode helper writes back) | TRANSFER | ❌ |
| `Assignment.php:576` | `$obj->prop[$k] = $v` (vec/assoc store) | TRANSFER | ❌ |

### Static property store (target is `Foo::$p`)

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `Assignment.php:429` | `Foo::$prop = $rhs` | ALIAS | ❌ |

### Array element store (target is `$arr[…]`)

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `Assignment.php:663` | `$arr[$k] = $v` (assoc via `__manticore_assoc_set`) | ALIAS for $v | ❌ |
| `Assignment.php:691` | `$arr[] = $v` (vec append after realloc) | ALIAS for $v | ❌ |
| `Assignment.php:721` | `$arr[$i] = $v` (vec indexed) | ALIAS for $v | ❌ |

### Variable RHS from non-Variable source

`compileAssign` at `Assignment.php:262-289` is the central dispatch.
Source kind determines whether retain is needed:

| Source kind | Pattern | Cat | Status |
|-------------|---------|-----|--------|
| `PropertyAccess` | `$x = $obj->prop` | ALIAS | ❌ |
| `MethodCall` | `$x = $obj->method()` | ALIAS (callee's rc transfers + caller retains) | ❌ |
| `Call` | `$x = foo()` | ALIAS | ❌ |
| `StaticCall` | `$x = Foo::method()` | ALIAS | ❌ |
| `ArrayAccess` | `$x = $arr[$k]` | ALIAS | ❌ |
| `NullCoalesce` | `$x = $a ?? $b` | ALIAS (per branch) | ❌ |
| `Ternary` | `$x = c ? a : b` | ALIAS (per branch via phi) | ❌ |

### Function / method / static call arguments

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `CallSites.php:149-164` | `foo($arr)` FFI arg (handled by `#[Ffi\Take]`) | ALIAS-or-TAKE | ❌ for non-FFI |
| `CallSites.php:223-247` | `foo($arr)` PHP-defined call arg | ALIAS | ❌ |
| `CallSites.php:223-265` | `foo(...$args)` variadic collection | TRANSFER (fresh vec) | ❌ |
| `ObjectDispatch.php:1048-1062` | `$obj->method($arr)` arg | ALIAS | ❌ |
| `ObjectDispatch.php:133-157` | `Foo::method($arr)` arg | ALIAS | ❌ |

### Return statement

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `StmtCompile.php:154-209` | `return $x` | TRANSFER | ❌ |
| `StmtCompile.php:181-183` | `return <expr>` (non-Variable) | TRANSFER (already rc=1 if fresh) | ❌ |

### Throw statement

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `ExceptionRuntime.php:74-77` | `throw $e` — store ptr to `__manticore_thrown` global | TRANSFER | ❌ |

### Foreach value binding

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `StmtCompile.php:395-404` | `foreach ($vec as $v)` per-iter store to $v | ALIAS | ❌ |
| `StmtCompile.php:922-944` | `foreach ($assoc as $v)` per-iter value | ALIAS | ❌ |
| `StmtCompile.php:948-957` | `foreach ($assoc as $k => $v)` per-iter key (string keys are ptr) | ALIAS | ❌ |

Note: by-ref foreach (`foreach … as &$v`) stores a cell address, not a
ptr value — no retain needed.

### Destructure assignment

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `Assignment.php:104-168` | `[$a, $b] = $src`, `['k' => $v] = $src` | ALIAS per slot | ❌ |
| `Assignment.php:182` | Destructure target is `$var` | ALIAS | ❌ |
| `Assignment.php:207` | Destructure target is `$obj->prop` | ALIAS | ❌ |

### Compound assignment & inc/dec

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `Arithmetic.php:79` | `$x += $y` (Variable target) | TRANSFER (in-place via cow) | ❌ |
| `Arithmetic.php:59` | `$obj->prop += $y` | TRANSFER | ❌ |
| `Arithmetic.php:101` | `$x++`, `++$x`, `$x--`, `--$x` | TRANSFER | n/a for ptr |

For ptr-typed compound, only `$x .= $y` (string concat) is relevant
today; assoc arrays don't typically participate in `+=`.

### Closure capture

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `ClosureResolution.php:443-594` | `use ($x)` lifts $x into closure's property | ALIAS | ❌ |
| `ObjectDispatch.php:537-652` | `new __Closure_N(captures…)` ctor args | ALIAS | ❌ |
| `ObjectDispatch.php:634-642` | Constructor stores capture into property | ALIAS | ❌ |

### Invoke / first-class callable

| Site | Pattern | Cat | Status |
|------|---------|-----|--------|
| `ObjectDispatch.php:344-438` | `$f($arr)` (closure invoke arg) | ALIAS | ❌ |

### Reference & cell sites (no action needed)

| Site | Pattern | Cat |
|------|---------|-----|
| `Assignment.php:68-96` | `$y = &$x` (cell sharing) | REF |
| `CallSites.php:236-244` | `foo(&$x)` (by-ref arg) | REF |
| `ObjectDispatch.php:1051-1059` | `$obj->method(&$x)` (by-ref arg) | REF |
| `StmtCompile.php:384-393` | `foreach (… as &$v)` (cell address) | REF |
| `StmtCompile.php:938-941` | `foreach ($assoc as &$v)` (cell address) | REF |

## Implementation plan

### Phase 1 — Build the retain/release machinery (foundation)

1. Promote `$assocOwnedLocals` to a first-class per-function set with
   the same save/restore discipline as `$ffiOwnedLocals`.
2. Add a tiny helper API on the `CallSites` trait:
   - `emitAssocRetain(Value $ptr): void`
   - `emitAssocReleaseLocal(string $name): void` (loads slot, calls release, drops from set)
   - `emitAssocReleasesOnScopeExit(): void` (releases every owned local; called at every exit point)
3. Keep all emits guarded by the local's `assoc` flag and slot type `ptr`.

### Phase 2 — Wire alias sites (one PR per category, small diffs)

In this order so that each landing is self-contained and testable:

1. **Variable→Variable** — ✅ landed (Step D).
2. **Variable RHS from PropertyAccess / ArrayAccess** — `$x = $obj->prop`,
   `$x = $arr[$k]`. Cheap; reads are simple.
3. **Variable RHS from Call / MethodCall / StaticCall** — the call's return
   is rc=1 if fresh (no retain) but rc>=1 if the callee returned a stored
   alias. Simplest correct rule: retain unconditionally; callee's matching
   release at scope exit balances it. Optimise later via escape analysis.
4. **Property / static-property store** — `$obj->prop = $rhs` retains the
   new value, releases the old slot value (if it was assoc-owned).
5. **Array element store** — `$arr[$k] = $v` retains $v in the buffer;
   the buffer's own refcount is unchanged.
6. **Call arguments** — caller retains each ptr arg; callee param is
   marked as owning on entry (Phase 3 below).
7. **Foreach value/key binding** — retain per iteration; release the
   previous binding before the next iter (handled by overwrite rule).
8. **Destructure** — retain each materialised slot.
9. **Closure capture** — retain each `use ($x)` into the closure's property.
10. **Ternary / null-coalesce** — retain in each branch before phi merge.
11. **Throw** — store-to-thrown-global must retain; catch handlers release
    on rethrow / scope exit.
12. **Return** — already handled (drop from owned set before compiling
    return expression).

### Phase 3 — Parameter ownership

Function parameters of ptr/assoc type get a retain at entry: the caller
hands a borrowed reference; the callee bumps it to own one. The callee's
scope-exit release then balances. This avoids the asymmetry where caller
retains on call but nobody releases on the callee side.

Alternative: caller does NOT retain on call (transfer-semantics for args),
callee does NOT retain on param entry, callee MUST NOT release. Cheaper
but limits the callee to read-only / move-only use of the param. Less
PHP-like. Start with the symmetric retain+release model.

### Phase 4 — Scope-exit release

Once every alias site retains symmetrically, scope-exit release is safe
to turn on. Each owned local releases its `+1`. The previously-flaky
runs (5-9 random failures per suite run) should reduce to zero because
no buffer has dangling owners outside the tracked set.

### Phase 5 — Cycle collector

Bacon-Rajan trial deletion or Lins-style synchronous cycle collection.
Triggered when a `release` brings rc from 2 → 1 on a candidate root
(object with one or more properties holding ptrs). Phase 5 is
independent — phases 1-4 ship correctness; phase 5 ships completeness
for cyclic graphs.

### Phase 6 — Escape analysis (optimisation)

Compile-time pass over a function's IR / typed-AST: a local is
**non-escaping** if it never flows into a property store, a return,
a call argument, or a thrown value. For non-escaping locals:

- Elide retain/release pairs entirely (single-owner, scope-bounded)
- Stack-allocate the buffer (zero heap traffic)
- Use raw `memcpy` for `$a = $b` when both are non-escaping

This is the layer where PHP catches up to Go-class performance for
typical script-style workloads. Most local arrays in idiomatic PHP
never escape.

## Object refcount

The same model extends to PHP class instances. Objects today live as
heap blocks with property slots at fixed offsets and a class-id word
in the header; they leak on process exit. Three additions promote them
to the refcounted layer:

### Header layout

```
+0   class_id          : i64
+8   refcount          : i64
+16  property slots ...
```

`new Foo()` sets `class_id` + `rc=1` before running `__construct`.
`#[Struct]` classes skip the refcount slot entirely — they live by
value (stack or inline in containers).

### Helpers — mirror the assoc set

- `__manticore_obj_retain(ptr)`  — `rc += 1`. No-op on NULL.
- `__manticore_obj_release(ptr)` — `rc -= 1`. On `rc == 0`:
    1. Call the class's `__destruct` if present.
    2. Release each ptr-typed property (recursive cleanup).
    3. `free(ptr)`.

Cow is **not** applicable to objects in PHP semantics: `$a = $b`
where both are objects shares the same instance (mutation on one is
visible on the other). So the retain side is identical to assoc, but
no clone path.

### Cycle collector

Objects can form cycles (`$a->ref = $b; $b->ref = $a`). Refcount
alone leaks cycles. Use Bacon-Rajan trial-deletion:

1. On a `release` that brings rc from N→N-1 with N-1 > 0, register
   the object as a "candidate root" — it might be part of a cycle.
2. Periodically (or at `gc_collect_cycles()`), run trial-deletion
   over the candidate list to find unreachable cycles and reclaim
   them.

Candidate list lives in a global vec touched on every release;
amortised cost is one append per "maybe-leaked" release. The
collection sweep itself runs rarely.

### Alias-site audit, extended

Every alias-site listed earlier in this doc applies to objects too —
PropertyAccess returning an object, MethodCall returning an object,
`$x = $obj`, etc. The same `exprProducesAssoc` predicate machinery
extends with an `exprProducesObject` peer (or merges into a single
`refcountedSource(Expr): 'alias' | 'transfer' | 'none'` predicate
that handles both assoc and object).

Compile-time slot type drives which retain helper to emit: object-
typed slot → `__manticore_obj_retain`, assoc → `__manticore_assoc_retain`.
For tagged (mixed) slots, dispatch at runtime via the NaN-box tag.

### Unified retain ABI (later)

Once both assoc and object refcount paths stabilise, factor out a
single `__manticore_value_retain(i64 v)` that inspects the tag bits:

```
switch tag:
  case TAG_PTR_ASSOC: assoc_retain(...)
  case TAG_OBJ:       obj_retain(...)
  default:            skip   // scalar
```

This is the layer where `array<mixed>` and `function(mixed): mixed`
boundaries can refcount uniformly without compile-time knowing which
concrete type a tagged slot holds.

### Phasing

Object refcount lands AFTER assoc refcount stabilises (Phase 4-5
green on assoc). Otherwise the bootstrap compiler has too many
moving parts at once. Order:

1. Finish Phase 4 / 5 / 6 on assoc — green tests with release on.
2. Add the object header rc field. New `__manticore_obj_retain/release`
   helpers. Mirror the assoc alias-site audit, retain at every site.
3. Add cycle collector (trial-deletion).
4. Unify into `__manticore_value_retain` for tagged slots.

Each phase reuses the same audit and toolkit (`MANTICORE_DEBUG_RC_TRACE`
extends naturally — just add an "obj" op tag in the trace output).

## Correctness invariants

To validate Phase 1-4 landings:

1. **rc balance**: total retains == total releases over a program's
   lifetime (ignoring intentional leaks at process exit). Verifiable
   via instrumented release that asserts `rc >= 0` before decrement.
2. **No stale ptr**: every dereference happens on a buffer with `rc >= 1`.
   Verifiable in debug builds by sentinel poisoning on free.
3. **CoW correctness**: mutating `$a` after `$b = $a` does not mutate
   `$b`. Already covered by `cow(rc>1) → clone`.
4. **No double-free**: a release of an already-freed buffer aborts.
   Guard with sentinel-on-free.

## Escape hatches: `#[Struct]` and `#[NoRefcount]`

Two language-level attributes that let user code opt out of the
refcount path when it's not needed. Both are critical for matching
Go/Rust-class performance on hot code without losing PHP semantics
elsewhere.

### `#[Struct]` — POD / value types

Mark a class as fixed-layout, no dynamic properties, no inheritance
(or only `#[Struct]` parents), pass-by-value semantics.

```php
#[Struct]
final class Point {
    public function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {}
}
```

Compiler treats `#[Struct]` classes as **value types**:

- **No refcount field.** Header is just the payload.
- **Stack allocation by default** for non-escaping instances. Instance
  lives in the caller's stack frame, no heap traffic.
- **Pass-by-value** at ABI level. SSA-friendly. LLVM aggregates instead
  of opaque ptrs.
- **`$a = $b` is a `memcpy`** (or full SSA copy), not an alias. Mutating
  one does not affect the other — value semantics, like PHP scalars.
- **No dispatch table.** Methods are direct calls, devirtualised.
- **Escape promotion.** If a `#[Struct]` instance escapes (stored in a
  refcounted container, returned from a function with non-struct
  return type), the compiler boxes it into a refcounted heap copy.
  Diagnostic on the boxing site so the user can tighten the boundary.

Self-host immediate payoff: most of the PHP-side compiler's internal
types (`ClassInfo`, `PropertyMeta`, `Local`, `StaticPropertyMeta`,
`DispatchTarget`, `LoopFrame`, `EnumCase`, `StaticLocalInit`) are
naturally `#[Struct]`. Marking them removes a large fraction of the
allocator traffic that drives bootstrap compile time. They also stop
needing the `__seed__` sentinel workarounds that exist today to fight
the assoc-array helpers.

`#[Struct]` is orthogonal to refcount: regular classes keep the
refcounted-heap model; structs opt out entirely. The user picks
per-class based on semantics, the compiler optimises mechanically.

### `#[NoRefcount]` — manual lifetime control

Mark a function or method body as "no automatic retain/release". The
compiler emits zero rc ops for ptr values inside the body. The author
is responsible for matching lifetimes manually — borrowed-only access,
explicit `assoc_retain` / `assoc_release` calls when ownership crosses
out of the function.

```php
#[NoRefcount]
function hot_inner_loop(array $items): int {
    // No retain on $items entry, no release on exit.
    // No retain on $row per iter. Author asserts $items
    // is borrowed for the call's duration.
    $sum = 0;
    foreach ($items as $row) {
        $sum += $row['value'];
    }
    return $sum;
}
```

When to use:

- **Hot loops** with read-only borrowing where every retain/release
  pair is pure overhead.
- **Internal compiler / runtime helpers** that the author knows are
  fully scope-bounded and never publish their refs.
- **FFI thin wrappers** where the underlying C library has its own
  ownership story.

Safety net: escape analysis (Phase 6) flags any expression inside a
`#[NoRefcount]` body that publishes a ptr to a persistent slot
(property store, return, throw, captured by closure). Either the
author manually inserts the retain, or the compile fails with a
"`#[NoRefcount]` published a borrowed ptr" diagnostic.

Default policy: refcount is on. `#[NoRefcount]` is the explicit
opt-out, like Rust `unsafe`. Audit-friendly: `grep -r '#\[NoRefcount\]'`
returns every place that bypasses the system.

### Granularity

Both attributes are per-declaration (per class for `#[Struct]`, per
function/method for `#[NoRefcount]`). No file-level or block-level
opt-out — keeps the scope-of-effect obvious from the declaration
header alone.

A future `#[Borrowed]` parameter attribute (callee promises not to
retain, caller keeps ownership) would let `#[NoRefcount]` callers pass
non-escaping refs to retain-aware callees without bumping rc, but
that's a phase-3+ refinement.

## Open questions

- Refcount field for **objects** (not just assoc buffers). Today objects
  are leak-on-exit. Layer 1 should extend to objects so closures and
  user classes participate in the same model.
- **String** buffer ownership. Most strings today are static-data (no
  free needed); concat results are leaked. Refcount-on-strings is a
  perf concern (constant inc/dec). Possible answer: short strings stay
  static-data forever; concat results live in arena per request.
- **Param mode** (caller-retain vs transfer-on-call). Symmetric retain
  is simpler; transfer-on-call avoids one inc/dec per call but
  complicates the calling convention. Decide before Phase 3.
- **`#[Struct]` vs regular class boundary**. When a regular class
  contains a `#[Struct]` property, the struct lives inline in the
  parent's heap block (no separate allocation). When a `#[Struct]` is
  passed where a regular class is expected (covariant scenario), the
  compiler must auto-box. Diagnostic vs implicit box — decide policy
  before exposing `#[Struct]` to users.
- **`#[NoRefcount]` and CoW**. Inside a `#[NoRefcount]` body,
  mutating helpers still call `cow()` because cow operates on the
  buffer's intrinsic refcount, not on caller's opt-out. Document
  this so authors don't expect `#[NoRefcount]` to mean "skip cow too".
  A separate `#[Raw]` attribute could opt out of cow as well, for
  unsafe in-place mutation — phase-3+ if anyone asks for it.

## Status

- Layer 1, Step A (32-byte header w/ rc): ✅
- Layer 1, Step B (retain/release/cow helpers): ✅
- Layer 1, Step C (cow gated on real assoc): ✅
- Layer 1, Step D (Variable→Variable retain): ✅
- Layer 1, Steps 2-12 (other alias sites): ⏳ this document
- Layer 1, Phase 3 (param ownership): ⏳ next
- Layer 1, Phase 4 (scope-exit release): ⏳ after retain coverage

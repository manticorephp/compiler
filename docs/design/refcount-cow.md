# Refcount + copy-on-write — why this model

Rationale for Manticore's memory model. The **layout and encoding** live in
[`memory-abi.md`](memory-abi.md); this doc is the argument for the design, kept because the
question "why not a GC?" keeps coming back.

> Trimmed from the former `docs/bootstrap/10-refcount-cow-design.md`. Its alias-site audit
> and six-phase implementation plan were an audit of the deleted AST backend — every file
> they cited (`Assignment.php`, `CallSites.php`, `ObjectDispatch.php`, `StmtCompile.php`,
> `ExceptionRuntime.php`) is gone, and the work shipped years of commits ago. §5 below says
> what replaced them.

## Goal

Predictable, deterministic memory management for compiled PHP: match Zend's refcount + CoW
semantics so user code observes correct value-vs-reference behaviour, then use escape
analysis to elide most of the refcount traffic for non-escaping locals.

## Why refcount + CoW — not GC, not arenas, not Boehm

- **PHP semantics natively refcount.** Zend, HHVM and PHP-CPP all use refcount + CoW.
  Matching the runtime model the language was designed for is how value semantics come out
  right for free.
- **Predictable latency.** No stop-the-world pauses — the point of an AOT PHP for servers
  and daemons.
- **AOT-friendly.** `inc`/`dec` of an i64 is a load + add + store in LLVM IR. It inlines
  trivially and links no external GC runtime.
- **Cycle handling is deferrable.** Cycles are rare in idiomatic PHP, so Bacon–Rajan trial
  deletion lands as an add-on without disturbing the model.
- **NaN-boxing compatible.** Tag bits identify ptr-vs-scalar at runtime, so refcount ops can
  hide behind a tag check where the value type is dynamic.

Rejected:

- **Boehm conservative GC** — a conservative scan blocks precise escape analysis, false roots
  leak, pauses are unpredictable.
- **Pure arena per scope** — wrong for PHP: arrays escape through `$obj->prop = $arr` and
  `return $arr` constantly, and the promotion logic degenerates into a worse refcount.
  (Arenas *did* survive as an optimisation for provably non-escaping allocations — see §5.)
- **Tracing GC, Go-style** — heavy runtime, write barriers everywhere, and it does not
  compose with NaN-boxing without precise stack maps.

## Layering

```
Layer 1: refcount + CoW        ← the foundation
Layer 2: cycle collector       ← correctness for cycles; manual-trigger today
Layer 3: escape analysis       ← the biggest perf win; elides most refcount ops
Layer 4: arena / stack alloc for non-escaping allocations
```

Each layer is independent, and 3–4 are optimisations over a correct layer-1 baseline. All
four are in the tree; layer 2 is the least finished (see `docs/ROADMAP.md`).

## The three primitives

- **`retain(ptr)`** — `rc += 1`. No-op on null. Emitted when a new owner appears.
- **`release(ptr)`** — `rc -= 1`; at zero run the destructor and free. No-op on null.
- **`cow(ptr) -> ptr`** — clone-if-shared. Returns the input when `rc <= 1`; otherwise
  allocates a copy with `rc = 1`, decrements the source, and returns the clone.

**CoW invariant:** every mutating helper calls `cow` on the buffer before touching it, so
mutation only ever lands on a singly-owned buffer and aliases stay invariant.

## The two ownership transitions

Every site where a pointer crosses a slot boundary is one of:

- **ALIAS** — a new owner appears alongside the existing one; both slots point at the same
  buffer. Needs a `retain` at the store.
- **TRANSFER** — the existing owner hands its reference over; the rc is unchanged.

In practice transfer is `return $x` and a few internal moves; almost everything else is an
alias.

**Scope-exit rule:** a local that owns a buffer contributed `+1`, and every exit path must
release it — explicit `return`, fall-through, `throw`, and re-entry through a catch handler.
`return $x` drops `$x` from the owned set *first*, so its reference transfers to the caller
instead of being over-released.

## What actually implements this

Not an expression predicate — a MIR pass chain:

```
InferEffects → InferAllocKind → ApplyMemoryMode → InsertMemoryOps → Verify
```

`InferAllocKind` is layers 3–4: it decides per allocation whether it can be arena-allocated,
must be heap-refcounted, or needs no refcount at all. `ApplyMemoryMode` applies the strategy
selected by `--memory` / `MANTICORE_MEMORY` (`hybrid` by default, `rc`, `arena`).
`InsertMemoryOps` plants the actual calls.

Conditional expressions are the one place where "who owns the result" cannot be read off a
single node, because the arms can disagree. `?:`, `??`, ternary and `match` therefore share
one predicate — `Compile\Mir\CondOwn` — consulted by both the pass and the emitter. Fixing
only the emitter leaks; fixing only the pass double-frees.

## Escape hatch

`#[Struct]` opts a class out of the whole model: no header, no retain / release, no
cycle-collector hook, fully static dispatch. It is the right answer for POD value types and
is documented in [`../attributes.md`](../attributes.md).

There is no `#[NoRefcount]` attribute, despite what older design notes proposed.
`AllocationKind::NoRefcount` is an escape-analysis *verdict* produced by `InferAllocKind`,
not a user-facing marker.

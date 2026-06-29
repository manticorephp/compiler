# Memory model — how Manticore manages memory, and how to control it

Manticore compiles PHP to native code with **no garbage-collected runtime**.
Every value (string, array, object) is reclaimed by one of three strategies,
chosen at compile time and refined per-allocation by escape analysis. This page
explains what happens by default and the knobs you have.

---

## The default: hybrid (escape-routed)

With no flag, Manticore runs in **hybrid** mode. An escape analysis
(`InferAllocKind`) classifies every allocation:

- **Confined** — the value never outlives the scope that created it (a local
  array built and consumed in the same function, never returned, stored, or
  captured). → allocated in an **arena**: a bump pointer, bulk-freed when the
  scope exits. No per-object bookkeeping.
- **Escaping** — the value is returned, stored in a property/global, captured by
  a closure, or pushed into an escaping container. → **reference counted** on
  the heap.

This gives arena speed for the common confined case and rc safety for the rest,
with no annotations.

### Reference counting

Escaping strings, arrays, and objects carry a refcount; assignment/retain bumps
it, scope exit/overwrite drops it, and reaching zero frees immediately
(deterministic — no GC pauses). Arrays and object properties use **copy-on-write**
(Zend-style): `$b = $a` shares the buffer until one side mutates.

Reference counting cannot reclaim **cycles** (`$a->x = $b; $b->x = $a`). Those
leak until you ask for them to be collected (see *Cycle collection* below).

### Arenas

A confined allocation goes to a process arena — a growing bump-pointer region.
Allocation is a pointer add; nothing is individually freed. At the owning scope's
exit the arena is rewound in bulk. This is why confined temporaries are cheap.

---

## Choosing a strategy

The mode is a **whole-program, compile-time** choice. There is no per-function
or per-scope override from code today (that's a roadmap item — see *Manual
control*).

| Mode | Confined allocations | Escaping allocations | Use when |
|------|----------------------|----------------------|----------|
| `hybrid` *(default)* | arena (bulk-free) | reference counted | almost always — best of both |
| `rc` | freed per-local at scope exit (no rc overhead) | reference counted | you want fully deterministic, arena-free reclaim |
| `arena` | arena | arena (+ a runtime escape-bypass guard) | short-lived batch/CLI runs where you never need mid-run reclaim — fastest allocation, frees everything at the end |

Select it:

```bash
manticore compile app.php -o app --memory=arena   # compile flag
MANTICORE_MEMORY=rc manticore compile app.php      # env var
```

`arena` mode trades memory for speed: nothing is reclaimed until scope/program
exit, so peak memory is higher, but there is zero refcount traffic. `rc` mode is
the most predictable for long-running processes. `hybrid` is the right default.

---

## Runtime control

The one memory operation you call from PHP code is cycle collection:

```php
$freed = gc_collect_cycles();   // run the collector, returns objects reclaimed
```

Manticore ships a synchronous **Bacon–Rajan cycle collector**. It is **opt-in
and zero-overhead**: a program that never calls `gc_collect_cycles()` pays
nothing for it (and cycles simply leak). Call it at a natural boundary (end of a
request, between batches) when you build reference cycles you need reclaimed.

Current limits: the collector is **manual-trigger only** (no automatic
threshold) and does not scan static/global roots — call it explicitly, and from
a scope where the cycle's roots are live locals.

---

## Manual control

"Manual" memory management today means **picking the model for your workload**
rather than fine-grained per-allocation control:

- **rc** — deterministic, immediate frees; you manage cycles with
  `gc_collect_cycles()`.
- **arena** — you accept bulk reclaim at scope/program exit (no per-object
  frees at all); ideal for a compile-and-exit tool like Manticore's own batch
  runs.
- **hybrid** — let the escape analysis decide; intervene only by restructuring
  code so a value stays confined (don't return/store it) when you want it
  arena-allocated.

Finer-grained manual control — a `#[Arena]` per-function hint, explicit arena
scopes, or `free()`-style release from code — is **designed but not yet wired**
(`Compile\Debug::$arenaScopedFns` is the reserved hook). For now, structure +
the global mode + `gc_collect_cycles()` are the levers.

---

## Under the hood (reference)

- Object header: `[ptr class-descriptor, i64 refcount, …properties]`. The
  descriptor (`{class_id, drop_fn}`) drives type checks and an indirect,
  link-composable destructor.
- Strings: an 8-byte refcount precedes the bytes; the value pointer is at the
  data, so `strlen`/`memcpy` see a plain C string. Immortal strings (literals,
  arena) carry refcount `-1` and are skipped by retain/release.
- Arrays: one unified `PhpArray` (vec + assoc) with a refcount and copy-on-write.
- Exact offsets and tag encodings: `src/Compile/MemoryAbi.php` (the single source
  of truth; `manticore version` reports its ABI version).
- Passes: `InferAllocKind` (escape analysis) → `ApplyMemoryMode` (mode overlay)
  → `InsertMemoryOps` (retain/release/CoW insertion). Design notes under
  `docs/bootstrap/`.

# Monomorphization — non-reified generics for the erased-array boundary

Status: **SHIPPED** — the `Monomorphize` pass (array dimension + the callable
dimension, `docs/design/monomorphize-callable-dim.md`) is in `main`. This doc is
the original engine design + rationale; read it with the callable-dim doc for
the current shape. Prereqs (landed): uniform closure ABI, cell/union type system
(type-system-v2.md), float literal precision.

## Why this is THE next milestone

Three independently-investigated gaps all converge on ONE root —
**bare-`array` element-type erasure**:

- **Multi-type prelude callbacks.** `array_map(fn($x)=>$x."!", [strings])` works
  ALONE, but in a program that ALSO calls `array_map(...)` over ints/floats, the
  call-site element inference is all-agree → it cannot pick one element type →
  the param erases to a bare `array` → a string element is a raw ptr (tag 0) read
  as a cell → tag-0 defaults to int → strings render as pointer-ints. int "works"
  only because tag-0 *defaults* to int.
- **array_sum / numeric accumulators.** `array_sum`'s `array $a` element erases to
  a plain cell → `$sum + $v` = `arithType(int, cell)` → unknown → `box_int(float
  bits)` at the use site → garbage.
- **Canonical NaN-boxing for float.** Blocked because the cell repr keeps RAW ints
  (tag 0) alongside boxed ints; canonical NaN-boxing needs "untagged = double",
  which requires EVERY int in a cell to be boxed — i.e. eliminating the erased
  raw-element path. (see float-nanbox-handoff memory.)

Fixing erasure unblocks all three plus general generics. The USER DESIGN CALL
(recorded across handoffs): **monomorphization (non-reified) is the generics path;
cell is the dynamic fallback; reflection is orthogonal.**

## The cell question (answered)

> "If it's a multi-type array_map, will they be cells?"

**No.** Each call-group gets its OWN specialized copy with the CONCRETE element
type — no boxing, no cell:

```
array_map(fn($x)=>$x*2,   [1,2,3])      → array_map$Lvec_int_R_Cint   (over vec[int])
array_map(fn($x)=>$x."!", ["a","b"])    → array_map$Lvec_str_R_Cstr   (over vec[string])
```

The specialized copy has `vec[int]` / `vec[string]` as the param, the loop reads a
typed element, the closure arg is boxed/unboxed per its REAL type (the closure ABI
is already correct), and the result is `vec[int]` / `vec[string]`. Cells appear
ONLY in the dynamic fallback (below) — when the element type is genuinely unknown
at every call site (e.g. an array built from heterogeneous runtime sources, or a
recursion-bounded type explosion). Monomorphization exists precisely to AVOID the
slow/lossy cell path.

## Scope: what gets monomorphized

A function is a monomorphization candidate when it has a parameter whose type is
**erased / polymorphic**: a bare `array` (element `unknown`), a bare `callable`,
`mixed`, or an unannotated param, AND its behaviour depends on that param's
concrete shape (element type, callee signature). The dominant cases:

- Prelude callback fns: `array_map`, `array_filter`, `array_reduce`, `usort`,
  `sort`/`rsort`, `array_walk`, … (today injected + all-agree-inferred).
- User generic helpers: `function first(array $a) { return $a[0]; }` used over
  `int[]` in one place and `string[]` in another.
- NOT monomorphized: fully-concrete functions (no erased param) — unchanged.

Closures are already specialized by capture; a monomorphized callee that invokes a
closure passes a CONCRETE element type, so the closure's arg box/unbox is exact.

## Architecture

A new MIR pass, **`Monomorphize`**, running AFTER `LowerFromAst` + the
class/function registry is built, and BEFORE (or interleaved with) `InferTypes`.
Reason: it must see call sites with enough type info to compute the specialization
key, but produce concrete `FunctionDef`s that `InferTypes` then types precisely.

Pipeline insertion:

```
… → LowerFromAst → Monomorphize → InferTypes → InferAllocKind → … → EmitLlvm
```

Algorithm (worklist / fixpoint):

1. **Seed.** Type the call graph enough to know each call site's argument types
   (a lightweight pre-inference, or reuse the call-site element inference already
   in InferTypes — see `scanParamElements`). For a polymorphic callee, compute a
   **specialization key** from the concrete arg types at the call site.
2. **Specialize.** For each (callee, key) not yet emitted, clone the callee's
   `FunctionDef`, substitute the concrete types into its params (bare `array` →
   `vec[T]` / `assoc[K,V]`, `callable` → the concrete closure class), name-mangle
   `callee$key`, and enqueue any NESTED polymorphic calls it makes (e.g. array_map
   calling the closure, or a helper calling another helper) — those get their own
   keys derived from the now-concrete types. This is the worklist fixpoint.
3. **Rewrite call sites.** Repoint each call to its specialized `callee$key`.
4. **Bound + fallback.** Cap the number of specializations per function (e.g. 8).
   On overflow, OR when a call site's arg type is genuinely `cell`/unknown (no
   concrete type), route that call to a single **`callee$cell`** copy whose erased
   param is `cell` and whose elements are boxed cells (today's behaviour, but now
   EXPLICIT and isolated — the slow path, used only when needed). This guarantees
   termination and a correct (if slower) result for genuinely dynamic code.

### Specialization key & mangling

Key = a canonical string over the monomorphizable params' concrete types, e.g.
`vec[int]`, `vec[string]`, `assoc[string,int]`, closure class id. Mangle into an
LLVM-safe suffix (reuse the existing `mangle()` + a type→token encoder; mirror the
generator/closure `__closure_N` style). Two call sites with the SAME key share one
specialization (dedupe via a `specialized: map<callee#key, FunctionDef>`).

### Interaction with the prelude

Prelude fns (array_map etc.) are injected as source today and compiled with the
user program. Monomorphize treats them like any other function — their multiple
call sites now yield multiple specializations instead of one all-agree (or erased)
copy. The injection/gating stays; only the post-injection specialization is new.
Drop the prelude comment's "one element type per call-group" limit once this lands.

### Interaction with InferTypes

After Monomorphize, every specialized fn has concrete param types, so InferTypes
types its body precisely (no erased element, no cell unless the `$cell` fallback).
This REMOVES the need for several existing erasure band-aids (`scanParamElements`
all-agree, the bare-array element refinements) — but KEEP them initially; retire
only after the new path is proven (they become no-ops when types are already
concrete).

## Foundation for explicit generics (frame the engine deliberately)

This IS the core of future generics — monomorphization is the canonical codegen
strategy for generics (Rust, C++ templates, .NET reified). What we build here is
the ENGINE; full generics layer a frontend + binding on top of the SAME engine:

- **The specialization mechanism is type-variable-AGNOSTIC.** Cloning a body,
  substituting concrete types, mangling, deduping, rewriting call sites, bound +
  `$cell` fallback — identical whether the type variable is IMPLICIT (today: the
  erased `array` element / a `callable` callee) or EXPLICIT (future: a `@template
  T`). Build it so it does not care where the binding came from.
- **Frame the specialization key as TYPE-VARIABLE BINDINGS, not just "concrete
  param types".** Today the key is derived from call-site arg types; model it as a
  map `{T → int, U → string}` even when there is a single implicit variable. Then
  an explicit `function head(array $a): T  /** @template T @param T[] $a @return T */`
  binds T from the arg and substitutes T EVERYWHERE it appears (param, return,
  body) — the `@return T` case the arg-types-only framing would miss. Substitution
  is "replace bound type variables", a strict generalization of "substitute param
  types".
- **Frontend is the only addition.** PHP has no native `<T>` syntax; the idiomatic
  source of type variables is docblocks (`@template T`, `@param T[]`, `@return T`)
  — which the existing docblock-driven type reader already parses for `@param
  int[]` etc. A future native-generics RFC extends the parser; the lowering +
  monomorphization engine is unchanged.
- **The `$cell` fallback is the erasure half** — exactly the "best of both" a mature
  generics impl uses (monomorphize the fast path, box/erase the dynamic path).

Net: doing monomorphization now lays the whole performance-critical foundation;
explicit generics later = a docblock/parser frontend + type-variable binding feeding
the same specialization engine. Keep the key as bindings to avoid a rewrite then.

## Reflection interaction (does NOT conflict — keep the layering)

Monomorphization is a LOWER-layer codegen optimization (multiple machine copies of
a function); runtime reflection is an UPPER layer keyed on SOURCE identities. They
do not interfere provided the boundary holds:

- **Object class identity is untouched.** Specialization is over function PARAMETER
  shapes, not object layout. A value still carries its own `class_id` (descriptor
  @ slot 0, drop-ABI), so `get_class($o)`, `$o instanceof X`, `ReflectionObject($o)`
  read the value's real class regardless of which specialized fn it flowed through.
- **Classes are NOT monomorphized** — only functions/generics over value-shape.
  Tier-2 reflection metadata tables are keyed on CLASS → untouched. Tier-1
  (class_exists/method_exists/…) are compile-time folds → orthogonal.
- **Reflection reports DECLARED signatures, not specializations.** `ReflectionFunction
  ('array_map')->getParameters()` reflects the SOURCE function with its declared
  `(?callable, array): array` — PHP has no reified generics, so reflection MUST
  report the erased declared types. Keep a function-metadata table keyed on the
  ORIGINAL name; specializations are invisible to it (cf. Rust reflecting the
  generic, not each instantiation).
- **The `$cell` fallback IS the name-addressable / dynamic / reflection entry.** A
  call by runtime name (`call_user_func('array_map', …)`, `$fn='array_map'; $fn(…)`,
  invoke-via-reflection) cannot pick a specialization at compile time → it routes
  to the single `$cell` copy. So the dynamic fallback is not only for unknown
  element types; it is also the canonical entry every name-based / reflective call
  reaches. INVARIANT: every monomorphized function keeps exactly one name-addressable
  `$cell` entry.

Net: as long as (a) object class_ids are preserved in values, (b) one canonical
`$cell` entry per function stays reachable by name, and (c) reflection metadata is
keyed on source names + declared types (never specialization keys), monomorphization
and runtime reflection coexist cleanly — they live at different layers.

## Why this also unblocks NaN-boxing & array_sum

- Once erased-element arrays are specialized (or boxed in the explicit `$cell`
  fallback), there is no implicit "raw element in a cell context" — the raw-int /
  raw-double collision that blocked canonical NaN-boxing disappears in the
  specialized path, and the `$cell` fallback can box ALL elements (ints included),
  giving canonical NaN-boxing a clean "untagged = double" invariant THERE.
- `array_sum$vec_int` returns int; `array_sum$vec_float` returns float;
  `array_sum$cell` returns numericCell — each correct, no int-seed-accumulator
  garbage.

## Phased plan (each phase fully gated)

Gate EVERY phase: `tests/aot/run.sh` (full) + `tools/difftest.sh` +
`tools/selfhost_fixpoint.sh` (fixpoint byte-identical + self-host suite +
stability 5×2) + `bin/build --seed` (the Zend cold-seed enforces PHP param hints
the native rebuild ignores). Revert-on-regression discipline (cf. NaN-boxing).

- **Phase 0 — skeleton + identity.** Add the `Monomorphize` pass that does NOTHING
  (pass-through), wired into the pipeline. Prove zero diff (fixpoint identical).
- **Phase 1 — one fn, one key.** Specialize a SINGLE simple user helper
  (`function head(array $a){return $a[0];}`) over two concrete element types in a
  test program. No prelude, no closures yet. Prove both render correctly.
- **Phase 2 — nested + closures.** Extend the worklist to follow nested
  polymorphic calls and closure invokes. Land multi-type `array_map`/`array_filter`
  over int AND string AND float in one program (the motivating bug). Retire the
  prelude "one element type per call-group" limit.
- **Phase 3 — the `$cell` fallback.** Make the erased→cell path EXPLICIT (a single
  `$cell` specialization with boxed elements) + the per-fn specialization cap.
  Prove a genuinely-dynamic program (heterogeneous runtime array) still works.
- **Phase 4 — accumulators.** `array_sum`/`array_reduce` specializations return the
  right scalar (int/float/numericCell). Fixes array_sum.
- **Phase 5 (separate, after monomorphization is stable) — canonical NaN-boxing
  retry.** Re-apply the NaN-boxing diff (reconstructable from float-nanbox-handoff:
  `cellTagHead` = `isd=icmp ugt v,-4503599627370496; tag=select isd,(v>>48&15),6`;
  `box_float`=`select(fcmp uno v,v),0x7FF8…,bitcast v`; asfloat→`bitcast i64 %v`),
  now safe because the `$cell` fallback boxes all ints (no raw-int/raw-double
  collision).

## Risks & lessons (carry forward)

- **Self-host is the hard gate.** The compiler compiles ITSELF; a monomorphization
  bug in the compiler's own (array-heavy) code corrupts the native compiler while
  Zend stays clean (the SwitchCase / float-repr heisenbugs). Validate via
  `bin/build --seed` AND a Zend dump; they diverge. Bisect with the bad-seed
  dprint method.
- **Code-size / compile-time explosion.** Bound specializations per fn; fall back
  to `$cell`. `log`/note any cap hit (silent truncation reads as "covered").
- **Param->type is in a readonly array** — fetch the Param to a local before
  mutating (cf. scanParamElements crash).
- **Don't compare computed method-call strings under self-host** — compare `->kind`.
- **Representation ≠ type:** specialization changes DISPATCH/var_dump/checking
  correctness, not raw representation — focus tests on those observable sites.
- Recovery from a mis-built native binary: `bin/build --seed`.

## First action for the dedicated session

Phase 0 + Phase 1: wire the pass (pass-through, prove fixpoint), then specialize a
single user helper over two element types with a focused test. Do NOT touch the
prelude or closures until Phase 1 is green through the full gate.

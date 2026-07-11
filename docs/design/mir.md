# MIR — Manticore's mid-level IR

MIR is the typed, tree-shaped IR between the AST and LLVM. Every analysis,
transform, and lowering step operates on it. This doc is the map: data
structures, the type lattice, the node taxonomy, the pass pipeline, and the
memory-management contract that turns escape analysis into concrete
retain/release/free/arena ops.

```
PHP → Lexer → Parser → AST → LowerFromAst → MIR → …passes… → EmitLlvm → LLVM IR → clang → binary
```

Source lives under `src/Compile/Mir/`. Codegen (`EmitLlvm*`) consumes MIR but
is not part of it.

---

## 1. Shape and philosophy

- **Tree, not basic blocks.** MIR keeps PHP's structured control flow as nodes
  (`If_`, `While_`, `For_`, `Foreach_`, `Switch_`, `Match_`, `TryCatch_`).
  There is no CFG / phi layer — "SSA-ish" means each transform rewrites the
  tree in place. LLVM's own SSA construction happens in EmitLlvm.
- **Flat node shape.** `Node` is a `kind` discriminant plus per-subclass
  payload (see §4). Deep subclass hierarchies are avoided on purpose: the
  self-host front-end pre-scans nodes by narrowing on `kind`, and a deep tree
  would push the compiled compiler past current limits. `Type` follows the
  same flat-`kind` rule (§3).
- **Mirrors the AST.** MIR node layout deliberately echoes `Parser\Ast\Expr`
  so a self-host walker can traverse both with the same idioms.
- **Every node carries a `Type`.** Lowering seeds `Type::unknown()`; passes
  refine toward a concrete shape. Later passes read the type as a
  precondition.
- **Mutable children, readonly leaves.** Child node refs (`->left`, `->value`,
  …) are *not* `readonly` — transform passes rewrite the tree. Leaf payloads
  (literal values, op strings, function names) stay `readonly`; a rewrite
  replaces the whole node rather than mutating a payload. Standard HHIR /
  Cranelift / LLVM discipline.

---

## 2. Top-level structures

| Type | File | Role |
|---|---|---|
| `Module` | `Module.php` | Whole-program unit: functions, classes, enums, interface/trait names, closure-capture counts, module-level global cells, and a `passesApplied` set. |
| `FunctionDef` | `FunctionDef.php` | One function: name, params, return type, body `Block`. Flags: `returnsByRef`, `isPrelude`, `isExtern` (declare-only stdlib import), `isGenerator`, `ffiSymbol` (+ C types) for `#[Symbol]` FFI. Aggregate `effects` filled by InferEffects. |
| `ClassDef` / `EnumDef` / `Param` | resp. | Layout descriptors and parameters. |
| `Node` (abstract) | `Node.php` | IR node base: `kind`, `type`, plus `effects` (InferEffects), `allocKind` (InferAllocKind), and `line` (source line for diagnostics). |

`Module` global cells (`globalNames`/`globalDefaults`, `globalVarNames`) back
static props, `static $x` locals, and `global $x` — stored as parallel arrays,
not a map of objects, because the self-host backend mishandles that.

`passesApplied` (`markPassApplied` / `hasPassApplied`) lets passes assert
preconditions and powers `dump-mir --after=<pass>`.

---

## 3. Type lattice (`Type.php`)

HHIR-inspired: scalar primitives, one unified array kind, object-by-class, a
tagged-union cell, and a static object union.

| Kind | Constructor | Notes |
|---|---|---|
| `void` `null` `bool` `int` `float` `string` | `Type::void()`, `int_()`, … | scalars |
| `array` | `Type::vec(el)`, `Type::assoc(key,val)` | **ONE** array kind. A *vec* has no explicit key (`key===null`, implicit int); an *assoc* has a `string` key. Packed-vs-hashed is a runtime detail, not a static kind. Discriminate via `isVec()` / `isAssoc()` / `isArray()`. |
| `array` + `fields` | `Type::record(fields, el)` | **Record shape**: a string-key literal's per-field types in insertion order. Representationally *identical* to `assoc[string, el]` — only `fields` is extra, only `isRecord()` readers see it. Any merge / element mutation drops it back to a plain assoc. |
| `obj<Class>` | `Type::obj(cls)` | object pointer. `Type::generator(v,k)` is `obj<Generator>` with yielded value/key in `element`/`key`. |
| `closure` | `Type::closure()` | |
| `cell` | `Type::cell(atoms)` | NaN-boxed tagged union; `atoms` narrows what it can hold. `Type::numericCell()` = `int|float` cell whose arithmetic may promote at runtime (the cell-arith path); a plain cell keeps the integer path (`isNumericCell()`). |
| `union` | `Type::union(arms)` | **Static object union** (`B|C`). Same repr as a bare object ptr (all arms are ptr, no boxing); a method call dispatches on the runtime `class_id`. ONLY all-object, ≤6 distinct classes form — otherwise degrades to `unknown`. One class collapses to `obj<…>`. Inert until a consumer opts in; every other consumer sees `unknown`. |
| `unknown` | `Type::unknown()` | raw i64, no static info. |

**Merges.** `unionWith()` joins types at control-flow merges: same kind →
refined join, else `unknown`. Object arms lift to a static `union`. Arrays
join element- *and* key-wise so a loop back-edge that appends a typed value
does not reset `vec[string]` to `vec[unknown]`; a null key (vec) joined with a
string key lifts to the string key. **Never collapse two known types to
unknown when a refinement exists** — that's a load-bearing principle.

`toString()` renders `vec[…]` / `assoc[k, v]` / `obj<C>` / `cell{a|b}` /
`a|b` — golden-stable, used by `dump-mir`.

---

## 4. Node taxonomy (`Node.php` + `Nodes.php`)

`kind` constants live on `Node`; concrete subclasses in `Nodes.php`, grouped:

- **Constants** — `IntConst`, `FloatConst`, `StringConst`, `BoolConst`, `NullConst`.
- **Locals** — `LoadLocal`, `StoreLocal`; `RefAlias_` (`$y = &$x`), `RefBind_` (`&fn()`).
- **Arithmetic / unary** — `Add`,`Sub`,`Mul`,`Div`,`Mod`,`Neg`,`Not_`,`BitOp` (shl/shr/and/or/xor),`BitNot_`,`Concat`. Binary nodes keep `left`/`right` in the same slot order so a base-`Node` read in `Walk` lands identically across them.
- **Compare / misc expr** — `Cmp`, `Ternary`, `Cast`, `Instanceof_`, `NullCoalesce_`, `IncDec`, `ClassName_`, `Isset_`, `Unset_`.
- **Statements** — `Echo_`, `Return_`, `Call`, `Block`, `Throw_`, `Yield_`.
- **Control flow** — `If_`, `While_`, `For_`, `DoWhile_`, `Foreach_`, `Switch_`(+`SwitchArm_`), `Match_`(+`MatchArm_`), `Break_`, `Continue_`, `TryCatch_`(+`MirCatch`).
- **Containers** — `ArrayLit`(+`ArrayElement_`), `ArrayAccess_`, `StoreElement`, `Spread_`.
- **Objects** — `NewObj`, `Clone_`(+`CloneWith`, 8.5 clone-with), `PropertyAccess_`, `StoreProperty`, `DynProp_`/`StoreDynProp_`, `MethodCall_`, `StaticCall_` (carries `staticClass` for late-static-binding), `Closure_`/`Invoke_`.
- **Statics / globals** — `StaticProp_`, `StoreStaticProp_`, `StaticLocalDecl_`.
- **Memory** — `MemoryOp_` (§6), inserted by InsertMemoryOps.

Notable payloads worth knowing:
- `Call.function` is non-readonly — Monomorphize repoints it to a specialized copy (`f$mono$p0_vec_int`) in place.
- `Foreach_` carries `iterClass` / `iterAggregate` (object-iterator dispatch, set by InferTypes) and `genSlotBase` (frame slot when inside a generator, so iterator state survives a `yield`).
- `TryCatch_` carries generator frame-slot indices (`genDepthSlot`, `genOuterSlot`, `genPendSlot`) so depth/finally state survives suspension.
- `Closure_` captures are index-parallel with `captureByRef` (by-value packs the value; by-ref packs the slot address).

`Walk.php` = generic child traversal / rewrite helpers; `NodeClone.php` = deep
copy (used by Monomorphize and inlining); `Dump.php` = the `dump-mir` printer.

---

## 5. Pass pipeline

Every pass implements `Pass` (`Pass.php`): `name()`, `requires()` (declared
dependency pass names), `run(Module): Module`. Each stamps `markPassApplied`.
`requires()` is declarative; the actual valid order is the fixed chain in
`Main.php::lower_module()`. Run order:

| # | Pass | File | What it does |
|---|---|---|---|
| 1 | **LowerFromAst** | `LowerFromAst.php` | AST → MIR. Seeds types as `unknown`, stamps `line`. Injects prelude sources, resolves stdlib externs (`isExtern`), marks generators, wires FFI `#[Symbol]`. |
| 2 | **ConstFold** | `ConstFold.php` | Fold constant expressions (`interface_exists`/`trait_exists` too). |
| 3 | **DeadStore** | `DeadStore.php` | Dead-store elimination. |
| 4 | **InferTypes** | `InferTypes.php` | The big type-inference pass (~148KB). Refines every node's `Type`; sets `Foreach_` iterator dispatch. |
| 5 | **NarrowReturns(preMono=true)** | `NarrowReturns.php` | Narrow concrete, param-independent bare-`array` returns early so call-site fusion sees a concrete element. Then **re-run InferTypes**. |
| 6 | **InlineClosures** | `InlineClosures.php` | Inline captureless arrow closures at known invoke sites; fuse `array_map`/`filter`/`reduce` over a concrete array + literal closure into a native typed loop. Then **re-run InferTypes**. |
| 7 | **Monomorphize** | `Monomorphize.php` | Specialize erased-array / polymorphic functions per call-site shape; repoints calls; re-runs InferTypes internally when it specializes. |
| 8 | **TypeCheck** *(gated)* | `TypeCheck.php` | `MANTICORE_TYPECHECK=1` only. Strict static analyzer; a real type error is fatal. Off during normal build / self-host. |
| 9 | **NarrowReturns** | `NarrowReturns.php` | Full post-Mono return narrowing. |
| 10 | **InferEffects** | `InferEffects.php` | Fill each node's `Effects` + the function aggregate (§6). |
| 11 | **InferAllocKind** | `InferAllocKind.php` | Escape analysis → `allocKind` on allocating nodes (§6). |
| 12 | **ApplyMemoryMode** | `ApplyMemoryMode.php` | Overlay the `--memory` mode (rc/arena/hybrid) onto the verdicts (§6). |
| 13 | **InsertMemoryOps** | `InsertMemoryOps.php` | Materialize `MemoryOp_` nodes (retain/release/cow/arena_enter/leave) from the verdicts. |
| 14 | **Verify** | `Verify.php` | Sanity gate before codegen. |

Then `EmitLlvm` (not a `Pass`) emits LLVM IR.

---

## 6. Memory-management contract

The reason MIR carries effect/alloc metadata: EmitLlvm must **consume**
explicit memory ops, never invent retain/release from ad-hoc feature handlers.
Four staged steps drive it.

**Step 3 — Effects** (`Effects.php`, filled by InferEffects). Per-node memory
effect set, unioned into the function aggregate:
`alloc` (fresh heap value), `escape` (outlives the frame — return/throw/store-
to-heap), `throw` (may unwind), `callUnknown` (opaque callee), `storeHeap`
(writes into a heap slot). `retain`/`release` stay false here — they're
vocabulary reserved for step 5.

**Step 4 — AllocationKind** (`AllocationKind.php`, decided by InferAllocKind).
Where an allocating value lives / how it's reclaimed:
- `RcHeap` — escapes → reference-counted heap.
- `NoRefcount` — proven frame-confined → freed at scope exit, no RC.
- `Arena` — bump-alloc, bulk-freed at arena scope end (see arena-arrays work).
- `Borrowed` — owned elsewhere (alias of a param/caller value).
- `Static` — global/constant lifetime, never freed.

**Soundness rule:** default to `RcHeap`; downgrade to `NoRefcount`/`Arena` only
when non-escape is *proven*. Over-marking `RcHeap` is merely slower;
under-marking is a use-after-free once step 5 acts on it.

**Step 5a — MemoryMode** (`MemoryMode.php`, applied by ApplyMemoryMode).
`--memory=<rc|arena|hybrid>` maps the verdict to a strategy. Default is
**hybrid**: confined → Arena, escaping → RcHeap. `rc`: confined → NoRefcount,
escaping → RcHeap. `arena`: everything → Arena (escaping needs a runtime
bypass guard to stay UAF-safe).

**Step 5b — MemoryOps** (`MemoryOp_`, inserted by InsertMemoryOps). Concrete
ops EmitLlvm lowers: `op` ∈ retain/release/cow/root/arena_enter/arena_leave;
`flavor` ∈ string/vec/assoc/obj/cell (the heap family the runtime helper
dispatches on); `target` = the acted-on value (or null for whole-frame arena
enter/leave).

> Arena arrays: the flat-scalar non-escaping array arena path is a live
> feature (default on, `MANTICORE_ARENA_ARRAYS`). See `docs/epic-arena-arrays.md`.

---

## 7. Inspecting MIR

```bash
bin/manticore dump-mir prog.php                 # MIR after the full pipeline
bin/manticore dump-mir prog.php --after=<pass>  # MIR state after a named pass
bin/manticore dump-ast prog.php                 # the AST it lowered from
bin/manticore dump-llvm-mir prog.php            # MIR pipeline + EmitLlvm → LLVM IR
bin/manticore dump-sig prog.php                 # inferred signatures
```

`--after=<pass>` uses each pass's `name()` (`lower-from-ast`, `infer-types`,
`infer-alloc-kind`, …). The printer is `Dump.php`; type rendering is
`Type::toString()`.

---

## 8. Reference files

| Concern | File |
|---|---|
| Node base + kinds | `src/Compile/Mir/Node.php` |
| Concrete nodes | `src/Compile/Mir/Nodes.php` |
| Type lattice | `src/Compile/Mir/Type.php` |
| Module / FunctionDef | `src/Compile/Mir/Module.php`, `FunctionDef.php` |
| Effects / AllocationKind / MemoryMode | `src/Compile/Mir/{Effects,AllocationKind,MemoryMode}.php` |
| Traversal / clone / dump | `src/Compile/Mir/{Walk,NodeClone,Dump}.php` |
| Passes | `src/Compile/Mir/Passes/*.php` |
| Pipeline wiring | `src/Manticore/Main.php` (`lower_module`) |
| Memory ABI (offsets, tags, rc encoding) | `src/Compile/MemoryAbi.php` |

Related design docs: `type-system-v2.md`, `monomorphization.md`,
`generators-and-pointers.md`, `../memory.md`, `../epic-arena-arrays.md`.

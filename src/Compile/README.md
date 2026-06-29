# `src/Compile/` — MIR pipeline (AST → LLVM IR)

Reference backend. Lowers the AST from `src/Parser/` into a flat,
typed, checked intermediate representation (MIR), runs analysis +
memory-management passes over it, then emits LLVM IR text.

```
PHP source --Parser--> AST
  --LowerFromAst--> MIR --[ analysis + memory passes ]--> MIR
  --EmitLlvm--> LLVM IR --clang--> binary
```

`src/Codegen/Llvm/` is the **low-level LLVM-IR text builder** (Module /
Block / Type / Value / FunctionDef) that `EmitLlvm` and the `Compile/Runtime`
hosts emit through. It carries no semantic logic — new emission belongs in
`Compile/Mir/Passes/EmitLlvm*`, not here. (The old AST `Compiler` backend was
removed; MIR is the only backend.)

## Public surface

### Core IR (`src/Compile/Mir/`)

| Symbol | Role |
|--------|------|
| `Module` | Whole-program unit: functions, classes, enums, globals, `passesApplied` ledger |
| `Node` (`Nodes.php`) | MIR node base, flat `kind` discriminant; ~64 node subclasses, each carrying a `Type` |
| `Type` | HHIR-style type lattice: void/null/bool/int/float/string, `vec[T]`, `assoc[K,V]`, `obj[class]`, `closure`, `cell` (tagged union of atoms), `unknown` |
| `FunctionDef` / `Param` | Function record: params, `returnType`, FFI binding, extern/prelude flags, inferred `Effects` |
| `ClassDef` / `EnumDef` | Class/enum layout descriptor (object ABI: class_id@0, rc@8, props@16+) |
| `AllocationKind` | Per-alloc verdict: RcHeap / NoRefcount / Arena / Borrowed / Static |
| `Effects` | Per-node + per-function memory-effect set (alloc/escape/throw/callUnknown/storeHeap…) |
| `MemoryMode` | rc / arena / hybrid reclaim strategy (default HYBRID) |
| `Pass` | Pass contract; `requires()` lists prerequisite pass names |
| `Walk` | Single source of truth for a node's value/control children |
| `Dump` | Diff-friendly MIR pretty-printer (`dump-mir`) |

### Memory ABI (`src/Compile/`)

| Symbol | Role |
|--------|------|
| `MemoryAbi` | One source of truth for object/array layout, rc encoding, tag magics, CC state bits. Versioned (`VERSION`), exposed via `manticore version` |
| `MemoryOp` | Semantic record of one rc op (retain/release/cow/possible_root × assoc/obj/vec) the lowering emits and audit passes read |

### Runtime IR host (`src/Compile/Runtime/`)

| Symbol | Role |
|--------|------|
| `RuntimeHost` | Contract the standalone runtime emitters need from a backend: alloc + labels/instrumentation |
| `BareHost` | MIR's host: plain libc `malloc`/`realloc`, no arena/profile/verify, private label counter |
| `UnifiedArrayRuntime` | The ONE PhpArray runtime (`docs/bootstrap/16`): 48-byte header, PACKED (vec fast path) ↔ HASHED (assoc map) modes, rc always at one offset |

### Type-hint parser (`src/Compile/TypeHint/`)

| Symbol | Role |
|--------|------|
| `GenericType` | Canonical parser for PHP type hints: `Foo[]`, `array<K,V>`, `?Foo`, `Foo\|null`, namespaced + union sugar |
| `GenericCursor` | Mutable scan cursor (class, not array tuple, for self-host stability) |

## Pipeline

Driven by `lower_module()` / `compile_via_mir()` in
`src/Manticore/Main.php`. Each pass takes a `Module`, returns a
`Module`, and stamps `passesApplied`:

```
LowerFromAst      AST → MIR; `array` hint lowers to `unknown`
   ↓
ConstFold         fold literal arith/cmp/unary; collapse dead `if`
   ↓
DeadStore         drop pure StoreLocal whose name is never read
   ↓
InferTypes        refine every node's Type from `unknown` (name→Type map)
   ↓
NarrowReturns     `unknown` array return → concrete `vec[T]` (fixpoint, re-runs InferTypes)
   ↓
InferEffects      stamp intrinsic Effects per node; union per function
   ↓
InferAllocKind    escape analysis → RcHeap (escapes) / NoRefcount (confined)
   ↓
ApplyMemoryMode   overlay --memory: confined → Arena (hybrid) | NoRefcount (rc)
   ↓
InsertMemoryOps   lower the verdict to explicit MemoryOp_ nodes (arena scope / per-local release)
   ↓
Verify            assert structural invariants; throw before bad MIR reaches LLVM
   ↓
EmitLlvm          MIR → LLVM IR text
```

`EmitLlvm` is split across `EmitLlvm.php` + four traits: `EmitLlvmBuiltins`
(PHP builtin call emitters), `EmitLlvmObjects` (new / prop / method /
static / refs / isset), `EmitLlvmExceptions` (throw / try-catch via
setjmp landing pads), `EmitLlvmRuntime` (tagged allocators + rc helpers).

## Key invariants

- Every value-producing node carries a non-null `Type`. Lowering seeds
  `unknown`; later passes refine and assert on it. `Verify` rejects a
  structurally null type.
- No dangling locals: every `LoadLocal`/`IncDec` use names a local
  defined somewhere in the function (param, store, foreach/catch var,
  static-local, ref-alias). `Verify` catches optimiser drops.
- Memory ops are **only** emitted by the memory passes, never invented
  by `EmitLlvm` feature handlers — that was the AST backend's mistake.
  `InferEffects` never sets retain/release; `MemoryOps` owns them.
- Allocation reclaim is a per-allocation escape verdict, not a global
  flag: RcHeap (escapes the frame, reference-counted) vs NoRefcount
  (frame-confined, freed at scope exit). `--memory` overlays the mode.
- One array layout (`UnifiedArrayRuntime`): a buffer starts PACKED and
  is promoted HASHED lazily on the first string/sparse-int key. rc lives
  at one fixed offset regardless of mode (kills the vec/assoc-layout
  heisenbug).
- Memory layout is versioned through `MemoryAbi` — every GEP offset
  flows through one constant there; bump `VERSION` on any layout change.
- Object layout: class_id@0, rc@8, props@16+. Single inheritance;
  virtual dispatch is a class-id chain collapsing to a direct call when
  one concrete class is reachable.
- Exceptions use setjmp/longjmp + a process-global thrown slot. No
  `__cxa_throw`. Injected Throwable/Exception/Error hierarchy.
- Self-host constraints shape the code: kind-discriminant dispatch over
  `instanceof` (bare short-name collisions across namespaces), return-value
  accumulators over `string &$out`, type-map held on `$this` over
  `&$param` snapshots.

## Memory modes

`--memory=<rc|arena|hybrid>`; MIR default is **hybrid**.

- `hybrid` confined → Arena (bump-alloc, bulk-free at scope exit), escaping → RcHeap
- `rc` confined → NoRefcount (per-local free at scope exit), escaping → RcHeap
- `arena` everything → Arena; escaping gets a runtime bypass guard

Cycles: Bacon–Rajan cycle collector v1 (opt-in, zero overhead unless
`gc_collect_cycles()` is called). Refcount + copy-on-write for assoc +
objects.

## Usage

```sh
bin/manticore dump-mir <file.php>       # typed MIR after the analysis/memory passes
bin/manticore dump-mir --effects <file> # annotate each op with inferred effects
bin/manticore dump-mir --prelude <file> # include the built-in Throwable hierarchy
bin/manticore dump-llvm-mir <file.php>  # run the full MIR pipeline + EmitLlvm, print LLVM IR
bin/manticore compile -o out < file.php # link a native binary via the MIR pipeline
```

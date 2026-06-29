# Codegen

Low-level LLVM-IR **text** builder. Everything here just assembles `.ll`
strings — declarations, globals, functions, basic blocks, instructions —
which are then handed to `clang` / `cc` to produce native object code and
link. No LLVM C API, no FFI, no `{$obj->prop}` interpolation (repo
convention): plain string concatenation only.

The single sub-namespace is `Codegen\Llvm\*` (`src/Codegen/Llvm/`).

## Builder classes (`Codegen\Llvm`)

| Class | Role |
|-------|------|
| `Module` | Top-level container: target triple / datalayout, headers, `declare`s, globals (`globalCString`, `globalInt`, `globalPtr`, `namedStruct`, `rawGlobal`), function defs. `emit()` renders the whole module; `emitFunctionsOnly()` renders globals + defs without the header for splicing a sub-module into another module. `escapeIrString()` byte-escapes IR string literals. |
| `FunctionDef` | One `define …` with params + basic blocks. Owns the per-function SSA-temp counter (`tempCounter`) shared by all its blocks. Carries `linkage` / `attrs` / `personality`. |
| `FnParam` | Function parameter record (type + name). |
| `Block` | Labeled basic block + instruction builder (`alloca`, `load`, `store`, `gep`, arithmetic, `icmp`/`fcmp`, casts, branches, `phi`/`select`, `call`/`invoke`, landing-pad/`resume`, `ret`/`unreachable`, `raw`). Auto-numbers temporaries via `fresh($hint)`. |
| `Type` | Immutable type value-object: `i1`/`i8`/`i16`/`i32`/`i64`/`f32`/`f64`/`ptr`/`void`, plus `array()`, `func()`, `raw()`. Tracks byte `size` when statically known. |
| `Value` | Typed operand — SSA reg (`%name`), global (`@name`), or immediate (int/float/bool/null). `typed()` formats `<type> <operand>`. |
| `PhiNode` / `PhiIncoming` | Phi instruction with deferred incoming-edge wiring (emitted once the block formats itself, so loop back-edges work). |
| `SwitchCase` | One arm (value + dest block) of `Block::switch_()`. |

See `Llvm/README.md` for the full per-class surface, invariants, and a
runnable example.

## Status — this is plumbing, not where codegen logic lives

This layer is the **mechanical IR-text builder only**. It does no
semantic analysis and no PHP→IR translation. The actual code-generation
logic — lowering MIR to LLVM, runtime/object/exception/builtin emission —
lives in the MIR pipeline:

- `src/Compile/Mir/Passes/EmitLlvm.php` (+ `EmitLlvmBuiltins`,
  `EmitLlvmObjects`, `EmitLlvmExceptions`, `EmitLlvmRuntime`) — the
  active emitter; it drives `Codegen\Llvm\Module` for the array-runtime
  sub-module.
- `src/Compile/Runtime/{RuntimeHost,BareHost,UnifiedArrayRuntime}.php` —
  use `Block` / `FunctionDef` / `Value` to emit spliced runtime IR.

**Do not add codegen features here.** New emission logic belongs in
`Compile/Mir/Passes/EmitLlvm*`. Treat `Codegen\Llvm` as stable
low-level plumbing: extend it only when the MIR emitter needs a new
primitive IR construct (a missing instruction / type / global form), and
keep it free of any PHP-semantic knowledge.

## Invariants

- Output is `string`. Nothing here writes files — the caller decides.
- No semantic validation; the builder trusts the caller. Errors surface
  from `clang` / `llc`.
- `Type` / `Value` are immutable value objects — share freely.
- `Block` auto-numbers temporaries (`%t1`, `%t2`, …); override via
  `Block::fresh($hint)`.
- `Module::func()` drops a prior matching `declare` so `clang` never sees
  both a `declare` and a `define` for one symbol.
- `anonString` uses a `.vstr.` prefix (not `.str.`) to avoid colliding
  with the MIR emitter's interned `@.str.N` pool.

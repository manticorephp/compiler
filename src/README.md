# `src/` — Manticore PHP source tree

PHP source for the self-hosted Manticore compiler and standard library. The
compiler driver (`bin/compile`) recursively walks this tree and links every
`.php` file into the emitted LLVM IR module.

## Layout

PSR-4-ish: one class / interface / trait / enum per file, file path mirrors
the fully-qualified name (`\Codegen\Llvm\Block` → `src/Codegen/Llvm/Block.php`).
Namespaced free functions group into a single `functions.php` per namespace.
Global stdlib functions (`ctype_*`, `str_*`, `array_*`, `gc_*`) live under
`src/Runtime/Stdlib/<Group>.php`.

## Top-level subdirectories

| Path | Namespace | Purpose |
|------|-----------|---------|
| `Cli/` | `\Cli` | CLI entry point (`bin/compile` driver) |
| `Codegen/Llvm/` | `\Codegen\Llvm` | LLVM IR text emitter (Module / Block / Type / Value / FunctionDef / PhiNode / SwitchCase) |
| `Compile/` | `\Compile` | AST → LLVM IR compiler, composed from trait modules |
| `Ffi/` | `\Ffi` | Attributes + helpers for shared-library bindings |
| `Lexer/` | `\Lexer` | PHP source tokenizer |
| `Manticore/` | `\Manticore` | Internal runtime glue |
| `Os/` | `\Os` | Process / libc-via-FFI helpers |
| `Parser/` | `\Parser` | Recursive-descent + Pratt parser, `Ast/` node classes |
| `Runtime/` | `\Runtime` | libc FFI bindings (`Libc.php`) + pure-PHP stdlib reimplementations under `Runtime/Stdlib/` |

`zzz_entry.php` is the top-level driver — name sorts last so every class /
function declaration has registered before its `exit(main_driver())` lowers
into the binary's `main`.

## Rules for new code

- One class / interface / trait / enum per file.
- File path mirrors fully-qualified name.
- Namespaced functions: group into `functions.php` per namespace.
- Global stdlib functions: group into `Runtime/Stdlib/<Group>.php`.
- `#[Attribute]` definitions live in their own file, not next to consumers.

## Discovery

`bin/compile` runs:

```
find src -name "*.php" | sort | xargs php tools/compile_files.php > out.ll
```

Sort order is deterministic. `zzz_entry.php` runs last by name.

For Zend-PHP development (the primary path right now), use
`tools/run_zend.php`, which registers a PSR-4 autoloader against `src/`
so bootstrap code runs without Manticore.

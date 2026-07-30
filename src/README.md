# `src/` — Manticore PHP source tree

PHP source for the self-hosted Manticore compiler and standard library. The cold
bootstrap (`bin/compile`) walks this tree and lowers every `.php` file into one
LLVM IR module; the normal loop (`bin/build`) builds it through the manifest,
with the compiler compiling itself.

## Layout

PSR-4-ish: one class / interface / trait / enum per file, file path mirrors the
fully-qualified name (`\Codegen\Llvm\Block` → `src/Codegen/Llvm/Block.php`).
Namespaced free functions live alongside the classes of their namespace — the
driver's own functions are in `src/Manticore/Main.php`. Global stdlib functions
(`ctype_*`, `str_*`, `array_*`, `gc_*`) live under `src/Runtime/Stdlib/<Group>.php`.

## Top-level subdirectories

| Path | Namespace | Purpose |
|---|---|---|
| `Analyze/` | `\Analyze` | Static analyzer: index, scope, flow walk, type inference, and `Rules/` (12 lint rules). Backs the `analyze` command *and* `compile`'s default analysis pass |
| `Cli/` | `\Cli` | Subcommand registry and argument parsing (`Cli`, `Command`, `ArgParse`, `ParsedArgs`) |
| `Codegen/Llvm/` | `\Codegen\Llvm` | LLVM IR text builder (Module / Block / Type / Value / FunctionDef / PhiNode / SwitchCase). No semantic logic |
| `Compile/` | `\Compile` | The MIR pipeline: AST → MIR lowering, ~20 analysis and memory passes, the `EmitLlvm` backend, and `MemoryAbi` |
| `Ffi/` | `\Ffi` | Attributes + the opaque `Ptr` type for native-library bindings |
| `Lexer/` | `\Lexer` | PHP source tokenizer |
| `Manticore/` | `\Manticore` | The driver: argv → sources → pipeline → clang/cc, the manifest build, the `.sig` format, and `Attr/` |
| `Parser/` | `\Parser` | Recursive-descent + Pratt parser, `Ast/` node classes |
| `Runtime/` | `\Runtime` | libc / OpenSSL / PCRE FFI bindings + the pure-PHP stdlib under `Runtime/Stdlib/` |

`zzz_entry.php` is the top-level driver — the name sorts last, so every class and
function declaration has registered before its `exit(main_driver())` lowers into
the binary's `main`.

## Rules for new code

- One class / interface / trait / enum per file.
- File path mirrors the fully-qualified name.
- Global stdlib functions: group into `Runtime/Stdlib/<Group>.php`.
- `#[Attribute]` definitions live in their own file, not next to consumers.
- ⚠ In `src/`, an **unqualified same-namespace call resolves differently** than
  you expect — Manticore flattens namespaces. Qualify calls that cross one.

## Discovery

The cold seed (`bin/compile`) runs:

```
find src -name "*.php" | sort | xargs php -d memory_limit=2048M tools/compile_files_mir.php > out.ll
```

Sort order is deterministic and `zzz_entry.php` runs last by name. That path is
the **cold bootstrap only** — use `bin/build` for the normal loop, so the native
binary rebuilds itself. A green `bin/build` says nothing about `bin/compile`, and
vice versa; see `docs/ROADMAP.md`.

`tools/compile_files_mir.php` is also the quickest way to inspect emitted IR for
one file:

```
MANTICORE_PRELUDE=$PWD/prelude php tools/compile_files_mir.php <file.php>
```

⚠ That dump does **not** link the stdlib, so a call into it resolves as
`unknown`. When that matters, read the final binary instead.

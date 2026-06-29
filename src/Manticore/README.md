# Manticore

Compiler driver: argv → sources → Parser → MIR pipeline → EmitLlvm → clang/cc → native binary. Also the `manticore build` module system and the `.sig` module-interface format.

The compiler self-builds via `manticore build manticore.json` (a self-contained application target whose own source defines the stdlib) to a byte-identical fixpoint.

## CLI subcommands

`main_driver()` wires `Cli\Cli` and registers:

| Command | Purpose |
|---------|---------|
| `compile` | Full pipeline → native binary. `-o <out>` (default `a.out`). Source from files, stdin, or `.manticore.php`. |
| `build [manticore.json]` | Cargo-like manifest build: every library target → `.o` + `.sig`, every application target → executable. |
| `dump-ast` | Parse first source, print AST (`Parser\Dump`). |
| `dump-mir` | Parse + run MIR pipeline (sans EmitLlvm), print typed IR. `--prelude` includes the Exception hierarchy, `--effects` annotates inferred memory effects. |
| `dump-llvm` | Front-end + EmitLlvm, print LLVM IR (no link). Same as `dump-llvm-mir`. |
| `dump-llvm-mir` | Same: parse → MIR pipeline → EmitLlvm → LLVM IR on stdout. |
| `dump-sig` | Parse + lower, print the module-interface `.sig` (exported symbol table). |
| `version` | `manticore 0.1.0 (self-hosted bootstrap)`. |
| `help` | Usage block. |

## `manticore build manticore.json`

Manifest decoded with native `json_decode` (values flow as `mixed`, extracted via `(string)` casts). Two target kinds:

- **libraries** — `{name, src, output, exclude}`. Compiled with `--emit-library` to a standalone `<output>.o` (no `@main`, no stdlib link) plus a sidecar `<output>.sig` written via `Sig::emitModule`.
- **applications** — `{name, src, output, entry, exclude}`. Module files (everything in `src` except `entry` + `exclude`) contribute declarations only; `entry` is appended LAST so its top-level lowers into `__main` after every class/function registers. With no `entry`, falls back to find|sort order (a `zzz_*` file sorts last by convention). Each app auto-depends on every library: imports each `<output>.sig` (declare-only externs, incl. namespaced/FFI bindings) and links each `<output>.o`.

Pipeline (`lower_module`): `LowerFromAst` → `ConstFold` → `DeadStore` → `InferTypes` → `NarrowReturns` → `InferEffects` → `InferAllocKind` → `ApplyMemoryMode` → `InsertMemoryOps` → `Verify`, then `EmitLlvm`.

## `.sig` module interface (`Sig.php`)

Serialized public symbol table of a library, so a dependent app resolves and types calls into the library without re-parsing its source.

- `Sig::emitModule(Module): string` — JSON (schema 1) of every exported function (real body, not `__main`/prelude/extern/closure). Each entry carries `name`, mangled `symbol` (`manticore_<name>`, `\`→`_`), `params` (name/type/byref/variadic/default), and `ret`. JSON hand-built (the bundled `json_encode` can't emit objects).
- `Sig::declsFromJson(string): FunctionDecl[]` — hydrates back into synthetic AST `FunctionDecl`s for the extern-injection path (`LowerFromAst::$externDecls`). Uses the real `json_decode`.
- Types encoded as hint STRINGS that `LowerFromAst::lowerTypeHint` already decodes (`string`, `mixed`, `string[]`, `array<string,mixed>`, `\ClassName`, …). Defaults const-folded to `{k,v}` (int/str/bool/float/null).

## CompileArgs flags (`parse_compile_args`)

Heterogeneous returns flatten to i64 in self-host today, so parsed args land in static props on `CompileArgs`:

- `-o <path>` → `$output` (default `a.out`).
- `-O<level>` → `$optLevel`, one of `0 1 2 3 s z`. **Default `2`.** Passed to clang.
- `--memory=<rc|arena|hybrid>` → `$memory`; routed through `Compile\Debug::applyMemoryMode`.
- `--backend=<mir|ast>` → `$backend`. MIR is the only real backend (default).
- `--emit-library` → `$emitLibrary`: build a standalone stdlib `.o` (no `@main`, no stdlib link).
- `--prelude` / `--effects` → `dump-mir` flags.
- Unknown flag → false (rc 64).

`$linkStdlib` (set when any bundled-stdlib extern was injected) and `$externDecls` (collected by `cmd_compile` on the native path) are internal carry-over fields, not CLI flags.

## Stdlib bundling

A user `compile` does NOT merge the stdlib source. Instead:

- `collect_stdlib_extern_decls()` — prefer the bundled `manticore_stdlib.sig` (`find_stdlib_sig`: `MANTICORE_STDLIB_SIG`, then `<argv0_dir>/../lib`, `/lib`, `/`); dev-tree fallback re-parses `src/Runtime` sources (`discover_stdlib_files`). Global-namespace decls only.
- At the cc step, when `$linkStdlib`, link the prebuilt object found by `find_stdlib_object()` (`MANTICORE_STDLIB_O`, then `<argv0_dir>/../lib/manticore_stdlib.o`, `/lib/...`, flat `...`).

A distributed compiler ships `bin/` + `lib/` only (no `src/Runtime`); the `.sig` + `.o` carry the full exported table, so bundled-stdlib calls type and link anywhere.

## Manifest loader (`Manifest.php`)

`Manifest::loadSources(string $path): ?string[]` — `.manticore.php` loader (distinct from the JSON `manticore.json` build). A PHP file declaring a `#[Manticore\Attr\Project]` class; `#[Module]` props give source roots, the single `#[Entry]` prop's string default the entry file. Returns sources in compile order: modules first, entry last.

## Attr (`Manticore\Attr\`)

- `Project(string $name)` — TARGET_CLASS; marks the manifest class.
- `Module(string $path)` — TARGET_PROPERTY; source-root directory.
- `Entry` — TARGET_PROPERTY; the property's string default is the entry-file path.
- `Struct` — TARGET_CLASS; value-type class (no class-id header, static dispatch).

## Invariants

- `dprint` writes fd 2 via the libc `write` FFI binding; under Zend that binding is a no-op — use `fwrite(STDERR, …)` for traces while running the compiler itself under Zend.
- Source order matters: module files lower first, entry last — its top-level becomes `__main` after all decls register.
- `read_file` / `read_stdin_source` use `calloc(n+1,1)` then `substr` into a real rc-headered string — the raw calloc block has no header and must never be rc-released.
- argv/argv0 C strings are likewise copied via `substr` before entering a vec (a raw headerless pointer would corrupt adjacent strings on retain).
- File discovery shells `find … > /tmp/manticore_*_<pid>.txt`, inlined to keep vec append in one frame (self-host element-type inference loses `string[]` across some call boundaries).
- The pipeline catches `Throwable` so an unsupported construct reports cleanly instead of longjmp-faulting on an unset handler jmp_buf in the self-hosted binary.

## Usage

```bash
manticore compile foo.php -o foo
manticore compile -o foo                 # reads stdin or .manticore.php
manticore compile foo.php -O0            # debuggable codegen
manticore build manticore.json           # manifest build (self-build path)
manticore dump-sig src/                   # exported symbol table
manticore dump-llvm foo.php
```

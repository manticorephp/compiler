# Manticore

Self-hosted PHP-to-native AOT compiler. Compiles a large subset of PHP 8.5+ to
standalone native binaries (arm64 / x86_64) through LLVM IR — no PHP runtime,
no shared libraries beyond libc. **The compiler is written in PHP and compiles
itself to a byte-identical fixpoint.**

## Rationale

`If you only knew the power of the dark side.`

Manticore is a proof-of-concept project that aims to demonstrate the
feasibility of creating a self-hosted PHP compiler. By writing the compiler in
PHP and compiling it to a byte-identical fixpoint, Manticore showcases the
potential for creating a fully self-contained PHP runtime environment.

## Status

- ✅ **Self-host fixpoint holds.** `manticore build manticore.json` rebuilds the
  compiler from PHP source; gen2 and gen3 emit byte-identical IR.
- ✅ **AOT suite 343/343** green (`tests/aot/`).
- ✅ **Differential parity 334/0** vs the Zend `php` interpreter (PHP 8.5.3) —
  manticore output matches the reference on every plain-runnable case.
- ✅ **Rebuild-stable** — 5×2 cold/self rebuilds, every binary smoke-clean (no
  build-to-build heap-layout roulette).
- ✅ **5–20× faster than Zend** on compute, ~24× faster cold start — see
  [Benchmarks](#benchmarks).
- ✅ Refcount + copy-on-write (assoc + objects) and a Bacon–Rajan cycle
  collector v1 (opt-in, zero-overhead unless `gc_collect_cycles()` is reached).
- ✅ Cargo-like module system: `manticore.json`, `.sig` module interfaces,
  prebuilt stdlib object, distributable compiler (`bin/` + `lib/`, no sources).
- ✅ Extension system (MVP): manifest-declared native-library bindings —
  glue compiled into the app + `-l<lib>` linked (proof: `zlib`/`crc32`). Real
  extensions (curl / xml / pdo) build on the same mechanism.
- ✅ **Broad PHP 8.5 surface.** Classes / interfaces / traits / enums,
  abstract + anonymous classes, late static binding (`new static`,
  `static::method()`, `parent::`/`self::` forwarding), magic methods
  (`__get`/`__set`/`__call`/`__invoke`/`__clone`/`__destruct`), `clone`-with,
  closures + first-class callable syntax (`f(...)`), dynamic callables
  (`call_user_func`, string/array callables), the pipe operator `|>`,
  heredoc / nowdoc, encapsed-string interpolation, `define`/constants, generators,
  exceptions, and file I/O over libc.

## Benchmarks

Native AOT output vs the Zend `php` interpreter (PHP 8.5.3). Apple M1 Pro,
`-O2`, median of 5 runs, each compiled program verified byte-equal to `php`
output first.

**Compute-bound** — native codegen dominates (the interpreter's per-op dispatch
is the tax we skip):

| Benchmark | Workload | `php` | manticore | Speedup |
|-----------|----------|------:|----------:|--------:|
| `oop`     | 2 M polymorphic virtual `area()`  | 0.122 s | 0.004 s | **28×** |
| `fib`     | `fib(32)` naive recursion         | 0.170 s | 0.009 s | **19×** |
| `strcat`  | 200 K-iter string append          | 0.055 s | 0.003 s | **18×** |
| `loop`    | 50 M-iter integer accumulate      | 0.297 s | 0.024 s | **12×** |
| `closures`| 2 M closure build + call          | 0.213 s | 0.019 s | **11×** |
| `array`   | build + sum a 1 M-element vec      | 0.066 s | 0.007 s | **10×** |
| `mathf`   | 3 M `sqrt` + `sin` accumulate     | 0.149 s | 0.018 s |  **8×** |
| `sieve`   | Sieve of Eratosthenes to 2 M      | 0.186 s | 0.028 s |  **7×** |

**Library-bound** — time is spent in the PHP-level stdlib/prelude (arrays,
strings, JSON), so the win is smaller and tracks how optimized that helper is:

| Benchmark | Workload | `php` | manticore | Speedup |
|-----------|----------|------:|----------:|--------:|
| `strops`  | `strtoupper`/`str_replace` loop   | 0.062 s | 0.019 s | **3.3×** |
| `wordcount`| assoc map build + iterate        | 0.100 s | 0.039 s | **2.6×** |
| `funcarr` | `array_map`/`filter`/`reduce`     | 0.104 s | 0.051 s | **2.0×** |
| `sprintf` | `sprintf` formatting loop         | 0.120 s | 0.068 s | **1.8×** |
| `json`    | `json_encode` loop                | 0.070 s | 0.043 s | **1.6×** |
| `sort`    | `sort()` 3 K ints × 200           | 0.105 s | 0.075 s | **1.4×** |
| `explode` | `explode`/`implode` loop          | 0.105 s | 0.111 s | **0.95×** |

- **Cold start** (`echo "hello"`): `php` 62 ms vs manticore **2.6 ms** (~24×) —
  no interpreter/extension init.
- **Compile time**: `fib.php` → native binary in **~0.1 s** (parse → MIR →
  LLVM → clang → link).
- **Output size**: a trivial program links to a **~50 KB** fully-static binary
  (libc only); the self-hosted compiler is ~2.9 MB.

Reproduce: compile each program with `bin/manticore compile … -o` and time the
binary against `php`. These are arithmetic/array/string microbenchmarks — they
exercise codegen + memory paths, not a real-app mix. The library-bound cases are
where the PHP-level stdlib is still being optimized (e.g. `sort` is an
O(n·log n) heapsort; `explode` round-trips through the boxed array path).

## Requirements

- **PHP 8.1+** — only for the cold bootstrap (Zend runs the compiler source once
  to seed the first native binary; emitted binaries make no PHP-runtime calls).
- **`clang`** and **`cc`** on `PATH` (Xcode CLT on macOS, `build-essential` on
  Debian/Ubuntu).

No Rust, no Cargo, no Composer required to build or run.

## Quick start

```bash
# Cold bootstrap: Zend seeds the first native compiler (no binary needed yet)
bash bin/compile                      # → bin/manticore  (~2.9 MB static binary)

# Thereafter, the compiler rebuilds itself (Zend seed is only the cold start)
bash bin/build                      # self-host: bin/manticore compiles src/
bash bin/build --verify             # + fixpoint + suite gate

# Compile and run a program
bin/manticore compile path/to/app.php -o app && ./app

# Source from stdin
echo '<?php echo "hi\n";' | bin/manticore compile -o /tmp/hi && /tmp/hi
```

## CLI

| Command | Purpose |
|---------|---------|
| `compile <file> -o <out>` | PHP source → native binary (file arg or stdin) |
| `build [manticore.json]` | Build all manifest targets (libraries + applications) |
| `dump-ast <file>` | Parse → print the AST |
| `dump-mir <file>` | Lower → print the typed MIR |
| `dump-llvm-mir <file>` | Full MIR pipeline → print LLVM IR |
| `dump-llvm <file>` | Same as `dump-llvm-mir` (the AST backend was removed; MIR is the only backend) |
| `dump-sig <files>` | Print the module-interface `.sig` (exported symbol table) |
| `version` / `help` | — |

Flags: `-o <out>`, `-O<0|1|2|3|s|z>` (clang opt level, default `-O2`),
`--emit-library` (compile to a standalone `.o` with no `@main`),
`--memory=rc|arena|hybrid`. `build` also takes `--libs-only` (build the
library targets and stop).

## Module system (`manticore.json`)

A cargo-style manifest builds multi-file projects, links prebuilt libraries, and
self-reproduces the compiler. **Full end-user guide: [`docs/modules.md`](docs/modules.md).**

```json
{
  "libraries": [{
    "name": "stdlib",
    "src": "src/Runtime",
    "output": "lib/manticore_stdlib.o",
    "runtime": true
  }],
  "applications": [{
    "name": "compiler",
    "src": "src",
    "output": "bin/manticore",
    "entry": "src/zzz_entry.php",
    "stdlib": false
  }]
}
```

- **`applications[]`** — `src` directory, `output` binary, optional `entry`
  (the file whose top level becomes `main`), optional `exclude`, optional
  `libraries` (user library deps — omit ⇒ all, `[]` ⇒ none), optional
  `stdlib: false` (opt out of the always-on stdlib runtime).
- **`libraries[]`** — compiled to `<output>.o` + an `<output>.o.sig` interface;
  an application links a user library by naming it. A `runtime: true` library
  (the stdlib) is built but auto-linked into every app (see above).
- **`.sig`** files carry a module's public symbol table so a dependent target
  resolves cross-unit calls *without* re-parsing the dependency's sources. A
  distributed compiler ships `bin/` + `lib/manticore_stdlib.{o,sig}` only — no
  PHP sources — and resolves the bundled stdlib by `.sig`.

The **stdlib is the always-on runtime**: every `compile`/`build` program gets it
transparently (no manifest ceremony), independent of the `libraries` selection;
opt out with `"stdlib": false` (only the self-contained compiler, which embeds
`src/Runtime`, does). See [`docs/modules.md`](docs/modules.md).

**Native libraries** (zlib, libcurl, …) bind through FFI — `#[Library, Symbol]`
attributes compile to direct C calls; declare them as manifest `extensions`.
Mechanism + type mapping: [`docs/ffi.md`](docs/ffi.md).

## Compiler pipeline

```
PHP source
  → Lexer            (src/Lexer)         tokens
  → Parser           (src/Parser)        AST  (recursive-descent + Pratt)
  → LowerFromAst     ─┐
  → ConstFold         │
  → DeadStore         │
  → InferTypes        │  MIR  (src/Compile/Mir) — flat, typed, SSA-ish IR
  → NarrowReturns     │  The only backend (the AST backend was removed);
  → InferEffects      │  EmitLlvm builds IR text via the src/Codegen/Llvm helpers.
  → InferAllocKind    │
  → ApplyMemoryMode   │
  → InsertMemoryOps   │  (rc retain/release/CoW insertion)
  → Verify           ─┘
  → EmitLlvm          (src/Compile/Mir/Passes/EmitLlvm*) → LLVM IR text
  → clang -c          IR → object
  → cc                link static binary (libc only)
```

## Memory model

Full guide + how to control it: [`docs/memory.md`](docs/memory.md).

- **Reference counting** on strings, objects, vecs, and assoc arrays, with
  **copy-on-write** for assoc snapshots/stores (Zend-style). Deterministic
  frees, no GC pauses.
- **Cycle collector v1** — synchronous Bacon–Rajan, **opt-in**: zero overhead
  unless a program reaches `gc_collect_cycles()`. (v1 limit: manual trigger;
  static/global roots not scanned.)
- **Allocation modes** (`--memory` / `MANTICORE_MEMORY`): `hybrid` *(default)*,
  `rc`, `arena` (bump-pointer, scope-freed). Escape analysis (`InferAllocKind`)
  routes each allocation between arena (confined) and heap-rc (escaping).
- The unified `PhpArray` is one runtime type (vec + assoc collapsed) with FNV
  bucket indexing for hashed keys.

## Source layout

Pure PHP, one class/interface/trait/enum per file, path mirrors FQN.

```
bin/            build & run scripts + the output binary
  compile         cold seed: Zend builds a throwaway seed, which then runs
                  `build manticore.json` → native bin/manticore + stdlib
  rebuild         self-host rebuild via the manifest (+ --seed, --verify)
lib/              prebuilt stdlib object + .sig (gitignored build artifacts)
src/Lexer/        tokenizer
src/Parser/       recursive-descent + Pratt parser; AST node types
src/Compile/      AST → MIR lowering, MIR passes, and the EmitLlvm backend
  Mir/              the typed IR (Node/Type/Module) + Passes/ pipeline
  Runtime/, TypeHint/, MemoryAbi.php, MemoryOp.php
src/Codegen/Llvm/ low-level LLVM-IR text builders (Module/Block/Type/Value)
                  used by EmitLlvm + the runtime hosts; no semantic logic
                  (new emission belongs in Compile/Mir/Passes/EmitLlvm*)
src/Runtime/      PHP-level stdlib + runtime helpers compiled into binaries,
                  plus libc / OS / Json bindings
src/Ffi/          FFI binding attributes
src/Os/           OS / syscall layer
src/Manticore/    driver (Main.php), Sig.php (module interfaces), build command
src/Cli/          CLI dispatch
tools/            build + gate scripts (selfhost, difftest, …)
tests/aot/        primary harness: cases/*.php + expected/*.out
docs/ROADMAP.md   status, gap matrix, planning method (start here)
docs/design/      design docs (module-system, type-system-v2, generators,
                  late-static-binding, monomorphization, …)
```

`src/zzz_entry.php` sorts last and holds the top-level `main_driver()` call the
binary's `main` lowers to.

## Self-hosting & gates

```bash
bash tests/aot/run.sh                 # AOT suite (328 cases)
bash tests/aot/run.sh -k hello        # filter by substring
bash tools/difftest.sh                # parity vs `php` (PHP 8.5.3)
bash tools/selfhost_fixpoint.sh       # fixpoint + self-host suite + stability
```

`bin/compile` cold-seeds (Zend → throwaway seed → native compiler via the
manifest); `bin/build` self-hosts. `selfhost_fixpoint.sh` asserts gen2 IR ==
gen3 IR, runs the suite through the self-built compiler, and rebuilds 5×2 to
catch layout roulette.

## Known limitations

- **Multi-object linking now composes.** A binary linked from two manticore
  objects (user `.o` + prebuilt `stdlib.o`) is correct and byte-identical:
  class ids are content-hashed (stable across objects), and object drops go
  through a per-class `linkonce_odr` descriptor + indirect drop_fn, so a class
  one object doesn't know still drops correctly. (Earlier two-object faults —
  the rc=139 era — are fixed.) The compiler still self-builds self-contained
  (stdlib embedded) for simplicity; user programs link the cached `stdlib.o`.
- Cycle collector is manual-trigger only; static/global roots not scanned.

## Roadmap / next steps

1. ✅ **Build entrypoint unified (#4).** `bin/compile` / `bin/build` both run
   `manticore build manticore.json`; the manifest is the single definition.
2. **Build cache.** A content-addressed cache (`~/.manticore/cache`, keyed by
   srchash + compiler ABI + target triple) to skip re-lowering/re-clang of
   unchanged modules. (Multi-object linking already composes correctly, so this
   is a speed feature, not a correctness one.)
3. **Extension system** — MVP shipped (manifest `extensions`, glue compiled in,
   `-l<lib>` linked; proof: `zlib`/`crc32`). Next: static-archive linking (keeps
   binaries fully static) + real extensions (curl / xml / pdo) on the same
   mechanism. Native libs are ordinary C archives and never touch the arena/rc
   runtime, so they add no corruption surface.
4. **Module system depth.** Weak library symbols (app can override), composer
   packaging + `dependencies` resolution (`vendor/`, lockfile),
   cross-library `.sig` classes, element-type precision.
5. **Memory.** Cycle-collector roots for statics/globals + automatic trigger;
   broaden arena/hybrid escape routing.
6. **Parity.** Continue closing `tools/difftest.sh` gaps toward the full PHP 8.5
   surface.
7. **(Optional, deferred)** Root-cause the two-object `-O2` fault — now
   deterministic and isolated — only if the two-object path is ever wanted for
   large programs (step 2 makes it moot).

## License

Licensed under the [MIT License](LICENSE).

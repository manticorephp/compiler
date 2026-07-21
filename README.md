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
- ✅ **AOT suite 467/467** green (`tests/aot/`).
- ✅ **Differential parity 458/0** vs the Zend `php` interpreter (PHP 8.5.8) —
  manticore output matches the reference on every plain-runnable case.
- ✅ **~228 standard-library functions** implemented (array 35, string 43, type
  30, math 28, ctype 11, `preg_*` 10, plus JSON / var-dump / SPL / date / I/O) —
  each as a PHP-level stdlib function, an injected prelude helper, or an inlined
  codegen builtin. See [Standard library](#standard-library).
- ✅ **Rebuild-stable** — 5×2 cold/self rebuilds, every binary smoke-clean (no
  build-to-build heap-layout roulette).
- ✅ **Faster than Zend on every benchmark** — up to **44×** on compute/algorithms,
  1.5–8× on the stdlib-bound tail, ~24× faster cold start — see
  [Benchmarks](#benchmarks).
- ✅ Refcount + copy-on-write (assoc + objects) and a Bacon–Rajan cycle
  collector v1 (opt-in, zero-overhead unless `gc_collect_cycles()` is reached).
- ✅ Cargo-like module system: `manticore.json`, `.sig` module interfaces,
  prebuilt stdlib object, distributable compiler (`bin/` + `lib/`, no sources).
- ✅ Extension system (MVP): manifest-declared native-library bindings —
  glue compiled into the app + `-l<lib>` linked (proof: `zlib`/`crc32`). Real
  extensions (curl / xml / pdo) build on the same mechanism.
- ✅ **Broad PHP 8.5 surface.** Classes / interfaces / traits / enums (incl.
  enum methods + constants and interface-implementing enums), abstract +
  anonymous classes, late static binding (`new static`, `static::method()`,
  `parent::`/`self::` forwarding), magic methods
  (`__get`/`__set`/`__call`/`__invoke`/`__clone`/`__destruct`), `clone`-with,
  8.4 **property hooks** + asymmetric visibility, closures + first-class callable
  syntax (`f(...)`), by-ref / variadic params + **argument unpacking** (`f(...$a)`),
  dynamic callables (`call_user_func`, string/array callables), the pipe operator
  `|>`, `match`, DNF types, null-coalescing / nullsafe (`??`, `?->`), `global` /
  `static` locals, heredoc / nowdoc, encapsed-string interpolation,
  `define`/constants, generators (`yield` / `yield from`), exceptions with
  `try`/`catch`/`finally` and stack traces (`getTrace` / `debug_backtrace`),
  the full `preg_*` family (10 functions via the host PCRE2 library),
  by-reference to an **array element / object property** (`f($a[0])`, `f($o->v)`)
  and out-parameter auto-vivification (`preg_match($re, $s, $m)`), reference
  returns (`function &f()`), and file I/O over libc.
- ✅ **Generics** (docblock-driven, so source stays valid PHP): `@template` with
  bounds (`T of C`) + defaults (`T = X`), `@extends`/`@implements`, generic
  **traits** (`@use T<X>`, zero-cost), and **reified** `@var Box<float> = new Box`
  (a real specialized class, no boxing) — plus implicit **monomorphization** of
  erased `array` / `callable` params. See [`docs/generics.md`](docs/generics.md).

## Benchmarks

Native AOT output vs the Zend `php` interpreter (PHP 8.5.3). Apple M1 Pro,
`-O2`, best of 5, each compiled program verified byte-equal to `php` first.
Every loop is **data-dependent and `$argc`-seeded** so the LLVM optimizer can't
fold it away — these numbers are real codegen throughput, not a deleted loop.
Reproduce with `bash bench/run.sh` (cases in `bench/cases/`).

**Compute-bound** — native codegen dominates (the interpreter's per-op dispatch
is the tax we skip):

| Benchmark | Workload | `php` | manticore | Speedup |
|-----------|----------|------:|----------:|--------:|
| `oop`     | 20 M polymorphic virtual `area()` (LCG-indexed) | 1.13 s | 0.04 s | **28×** |
| `fib`     | recursive fib, data-dependent depth [30,33] | 1.86 s | 0.10 s | **19×** |
| `closures`| 2 M closure build + call          | 0.19 s | 0.01 s | **19×** |
| `mathf`   | 3 M `sqrt` + `sin` accumulate     | 0.16 s | 0.01 s | **16×** |
| `loop`    | 50 M-iter data-dependent accumulate | 0.68 s | 0.06 s | **11×** |
| `sieve`   | Sieve of Eratosthenes to 2 M      | 0.20 s | 0.02 s | **10×** |
| `array`   | 30× build + sum a 500 K-element vec | 0.35 s | 0.07 s |  **5.0×** |
| `strcat`  | 30 M-iter string append           | 0.37 s | 0.11 s | **3.4×** |

**Algorithms** (Computer Language Benchmarks Game style — float math, tight
loops, 2-D arrays):

| Benchmark | `php` | manticore | Speedup |
|-----------|------:|----------:|--------:|
| `spectralnorm` | 0.44 s | 0.01 s | **44×** |
| `mandelbrot`   | 0.26 s | 0.03 s | **8.7×** |
| `matmul`       | 0.08 s | 0.01 s |  **8×** |
| `dijkstra`     | 0.14 s | 0.03 s | **4.7×** |
| `nbody`        | 0.17 s | 0.04 s | **4.2×** |

**Library-bound** — time is spent in the PHP-level stdlib/prelude (arrays,
strings, JSON), so the win is smaller and tracks how optimized that helper is,
and how much it competes with PHP's hand-tuned C:

| Benchmark | Workload | `php` | manticore | Speedup |
|-----------|----------|------:|----------:|--------:|
| `funcarr` | `array_map`/`filter`/`reduce`     | 0.16 s | 0.02 s | **8.0×** |
| `strops`  | `strtoupper`/`str_replace` loop   | 0.07 s | 0.02 s | **3.5×** |
| `wordcount`| assoc map build + iterate        | 0.07 s | 0.02 s | **3.5×** |
| `sort`    | `sort()` 3 K ints × 200           | 0.12 s | 0.05 s | **2.4×** |
| `sprintf` | `sprintf` formatting loop         | 0.09 s | 0.04 s | **2.2×** |
| `in_array`| `in_array` linear scan over a vec | 0.15 s | 0.07 s | **2.1×** |
| `explode` | `explode`/`implode` loop (fused)  | 0.14 s | 0.07 s | **2.0×** |
| `assoc`   | string-keyed map build + lookup   | 0.15 s | 0.08 s | **1.9×** |
| `json`    | `json_encode` loop                | 0.12 s | 0.08 s | **1.5×** |

- **Cold start** (`echo "hello"`): `php` 62 ms vs manticore **2.6 ms** (~24×) —
  no interpreter/extension init.
- **Compile time**: `fib.php` → native binary in **~0.1 s** (parse → MIR →
  LLVM → clang → link).
- **Output size**: a trivial program links to a **~50 KB** fully-static binary
  (libc only); the self-hosted compiler is ~4.3 MB.

The library-bound tail is the tightest race — these lean on stdlib/prelude
helpers competing with PHP's hand-tuned C, yet native still wins every one.
`json_encode` is a native single-buffer codegen builtin (a recursive cell walk
that formats ints/floats and escapes strings straight into one growing buffer),
`explode`/`implode` round-trips fuse into a single native `str_replace`, assoc
lookups cache each string's hash in its header (Zend `zend_string` style), and
string builders (`$s = $s . …`) append in place, not the former O(n²) copy.

## Requirements

Emitted binaries are fully static and depend on nothing but libc. The
*compiler* needs a real toolchain on the host:

- **`clang`** and **`cc`** on `PATH`, with **LLVM ≥ 15** — Manticore emits
  opaque-pointer IR, which clang 14 rejects.
- **PHP 8.5** — only for the cold bootstrap (Zend runs the compiler source once
  to seed the first native binary; emitted binaries make no PHP-runtime calls).
- **libpcre2** (`preg_*`) and **OpenSSL 3** (TLS, `hash`/`hmac`) development
  packages, plus `pcre2-config` / `pkg-config` to locate them.

Both **macOS** (arm64 / x86_64) and **Linux** (glibc ≥ 2.33, arm64 / x86_64) are
supported — each builds the compiler and passes the full suite, including the
self-host fixpoint.

**Per-OS package lists, Docker images and troubleshooting:
[`docs/install.md`](docs/install.md).**

## Quick start

```bash
# Install: the compiler builds itself into ~/.manticore (needs the toolchain above)
curl -fsSL https://raw.githubusercontent.com/manticorephp/compiler/main/install.sh | bash
export PATH="$HOME/.manticore/bin:$PATH"

# ...or, from a checkout:

# Cold bootstrap: Zend seeds the first native compiler (no binary needed yet)
bash bin/compile                      # → bin/manticore  (~4.3 MB static binary)

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

## Standard library

**~228 PHP standard-library functions** are implemented across three tiers, all
exposed to user programs transparently (no imports, no registration):

| Family | Count | Examples |
|--------|------:|----------|
| `array_*` | 35 | `array_map`/`filter`/`reduce`, `usort`/`uasort`/`uksort`, `array_merge`, `array_column`, `array_diff`, `array_unique` |
| String | 43 | `str_replace`, `substr`, `explode`/`implode`, `sprintf`, `preg_*` (10, via host PCRE2), `str_pad`, `wordwrap`, `levenshtein` |
| Type / reflection | 30 | `is_*`, `gettype`, `get_class`, `get_object_vars`, `class_exists`, `method_exists` |
| Math | 28 | `abs`, `sqrt`, trig, `intdiv`, `fmod`, `round`, `pow`, `max`/`min` |
| `ctype_*` | 11 | `ctype_digit`, `ctype_alpha`, … |
| Var / JSON / SPL / date / I/O | remainder | `var_dump`, `var_export`, `print_r`, `json_encode`, `SplStack`/`SplQueue`, `time`/`date`, `fopen`/`fread` over libc |

Each function is one of: a **PHP-level stdlib function** (`src/Runtime/Stdlib/`,
compiled into `lib/manticore_stdlib.o` and auto-linked), an **injected prelude
helper** (`prelude/`, inlined into each program), or an **inlined codegen
builtin** (`src/Compile/Mir/Passes/EmitLlvmBuiltins.php`, emitted as a primitive
/ libc call / LLVM intrinsic). See `docs/ROADMAP.md` for the gap matrix.

## Compiler pipeline

```
PHP source
  → Lexer            (src/Lexer)         tokens
  → Parser           (src/Parser)        AST  (recursive-descent + Pratt)
  → LowerFromAst     ─┐
  → ConstFold         │
  → DeadStore         │
  → InferTypes        │  MIR  (src/Compile/Mir) — flat, typed, SSA-ish IR
  → InlineClosures    │  The only backend (the AST backend was removed);
  → Monomorphize      │  EmitLlvm builds IR text via the src/Codegen/Llvm helpers.
  → NarrowReturns     │  Monomorphize specializes erased-array / callable params
  → CheckTypeDefs     │  per concrete call-site shape (see docs/design).
  → DemoteCharLocals  │
  → InferEffects      │
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
  build           self-host rebuild via the manifest (+ --seed, --verify)
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
bash tests/aot/run.sh                 # AOT suite (467 cases)
bash tests/aot/run.sh -k hello        # filter by substring
bash tools/difftest.sh                # parity vs `php` (PHP 8.5.8)
bash tools/selfhost_fixpoint.sh       # fixpoint + self-host suite + stability
```

`bin/compile` cold-seeds (Zend → throwaway seed → native compiler via the
manifest); `bin/build` self-hosts. `selfhost_fixpoint.sh` asserts gen2 IR ==
gen3 IR, runs the suite through the self-built compiler, and rebuilds 5×2 to
catch layout roulette.

## Known limitations

- **Integer overflow wraps** (two's-complement) instead of promoting to float
  as PHP does — `PHP_INT_MAX + 1` gives `PHP_INT_MIN`, not a float.
- **Dynamic name resolution** is not yet supported — `new $cls()`, `$cls::m()`,
  `$o->$m()`, `$o->$prop`, a computed-string `$f()`, `$obj instanceof $cls`, and
  `ReflectionClass`. (Literal / first-class / `call_user_func($strVar, …)`
  callables do work.)
- **`extract()`** is not implemented (it needs dynamic symbol-table writes the
  typed frame does not model). `compact()` works.
- **`goto` into a loop body** is unsupported (plain forward/backward `goto`
  works).
- **Cycle collector** is manual-trigger only; static/global roots not scanned.
- **Multi-object linking composes** (resolved): a binary linked from two
  manticore objects (user `.o` + prebuilt `stdlib.o`) is correct and
  byte-identical — class ids are content-hashed (stable across objects) and
  drops route through a per-class `linkonce_odr` descriptor + indirect drop_fn,
  so a class one object doesn't know still drops correctly. The compiler still
  self-builds self-contained (stdlib embedded) for simplicity; user programs
  link the cached `stdlib.o`.

## Roadmap / next steps

1. **Parity tail.** Close the remaining `tools/difftest.sh` gaps toward the full
   PHP 8.5 surface: integer overflow → float promotion, the `sprintf` flag
   corners (`%b`, width+precision, `%e` exponent), `extract`/`compact`. Each is a
   scoped stdlib/codegen fix, not an architectural one.
2. **Representation soundness.** Continue the typed⇄cell array reabstraction the
   monomorphization + de-cellify work opened (`docs/design/unknown-cell-soundness.md`,
   `docs/design/monomorphize-callable-dim.md`): erased-array boundaries now
   specialize + de-cellify at stores; broaden the same discipline to the last
   raw-guessing consumers.
3. **Build cache.** A content-addressed cache (`~/.manticore/cache`, keyed by
   srchash + compiler ABI + target triple) to skip re-lowering/re-clang of
   unchanged modules — a speed feature (multi-object linking already composes).
4. **Extension system.** MVP shipped (manifest `extensions`, glue compiled in,
   `-l<lib>` linked; proof: `zlib`/`crc32`, and the `preg_*` family over host
   PCRE2). Next: static-archive linking (keeps binaries fully static) + real
   extensions (curl / xml / pdo) on the same FFI mechanism.
5. **Module system depth.** Weak library symbols (app can override), composer
   packaging + `dependencies` resolution (`vendor/`, lockfile), cross-library
   `.sig` classes.
6. **Memory.** Cycle-collector roots for statics/globals + automatic trigger;
   broaden arena/hybrid escape routing.

## License

Licensed under the [MIT License](LICENSE).

[issue #1]: https://github.com/manticorephp/compiler/issues/1

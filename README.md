# Manticore

Self-hosted PHP-to-native AOT compiler. Compiles a large subset of PHP 8.5+ to
standalone native binaries (arm64 / x86_64) through LLVM IR — no PHP runtime, no
interpreter, no extension loader. **The compiler is written in PHP and compiles itself
to a byte-identical fixpoint.**

```bash
manticore compile app.php -o app && ./app      # one file → one static binary
```

The output has no interpreter to install, no `php.ini`, no extension list — you ship
the binary. What you write is ordinary PHP: the Zend interpreter is the reference,
and every plain-runnable test case is diffed against it. On top of that sits a
[superset](docs/superset.md) `php` cannot run at all — structured concurrency, FFI,
a module system, compile-time attributes.

---

## Requirements

**Emitted binaries carry no PHP runtime**; they link dynamically against libc plus
the two system libraries the stdlib rides on — PCRE2 (`preg_*`) and OpenSSL 3
(TLS, `hash`/`hmac`) — and, on macOS, libiconv. A program that binds a native
library through FFI (`PDO` → sqlite3, `curl_*` → libcurl, …) adds that library to
its link line on demand. The *compiler* needs the same libraries as dev packages,
plus a real toolchain, because it ends in `clang` and `cc`:

| What | Version | Why |
|---|---|---|
| `clang` + `cc` on `PATH` | **LLVM ≥ 15** | Manticore emits opaque-pointer IR; clang 14 rejects it |
| `php` | **8.5** | cold bootstrap only — Zend runs the compiler source once to seed the first native binary |
| libpcre2 (**dev** package) | 10.x | `preg_*` rides host PCRE2; needs `pcre2-config`; emitted binaries link it |
| OpenSSL 3 (**dev** package) | 3.x | TLS, `hash`/`hmac`; needs `pkg-config`; emitted binaries link it |

The `-dev` / `-devel` half matters: the headers are what the build looks for, not just
the runtime library.

**Platforms:** macOS (arm64 / x86_64) and Linux (glibc ≥ 2.33, arm64 / x86_64). Each
builds the compiler and passes the full suite including the self-host fixpoint. Alpine
(musl) builds; see [`docs/install.md`](docs/install.md) for the current caveats.

```bash
# macOS
brew install php pcre2 openssl@3 pkg-config

# Debian / Ubuntu — plus clang from your distro or apt.llvm.org, and PHP 8.5 from sury.org
sudo apt-get install -y build-essential pkg-config libpcre2-dev libssl-dev
```

Per-OS package lists, Docker images and troubleshooting:
**[`docs/install.md`](docs/install.md)**.

## Install

There is no prebuilt binary to download — the compiler compiles itself. The installer
checks the toolchain above, tells you what is missing, then installs under
`$MANTICORE_HOME` (default `~/.manticore`):

```bash
curl -fsSL https://raw.githubusercontent.com/manticorephp/compiler/main/install.sh | bash
export PATH="$HOME/.manticore/bin:$PATH"

manticore version        # manticore 0.10.0
```

Re-running the installer **upgrades in place**: an existing `manticore` rebuilds the
new version *with itself* (self-host, fast) — the Zend seed is only the cold first
boot. Knobs: `MANTICORE_HOME`, `MANTICORE_REF` (branch/tag), `MANTICORE_REPO`,
`MANTICORE_SRC` (build a local checkout instead of cloning).

Via Composer, which here is a delivery + build trigger rather than a runtime:

```bash
composer create-project manticorephp/compiler manticore   # builds into ~/.manticore
vendor/bin/manticore-install                              # if it is already a dependency
```

The installed layout is self-contained and needs no environment variables — the binary
finds its runtime relative to itself:

```
$MANTICORE_HOME/bin/manticore
$MANTICORE_HOME/lib/manticore_stdlib.o(.sig)
$MANTICORE_HOME/lib/prelude/*.php
```

### From a checkout

```bash
bin/compile              # cold bootstrap: Zend seeds the first native compiler
bin/build                # thereafter it rebuilds ITSELF (this is the normal loop)
bin/build --verify       # + fixpoint + suite gate
```

## Working with it

```bash
# one file
manticore compile path/to/app.php -o app && ./app
echo '<?php echo "hi\n";' | manticore compile -o /tmp/hi && /tmp/hi

# a project — a cargo-style manifest, see docs/modules.md
manticore build                    # reads ./manticore.json
manticore build --libs-only        # library targets only

# a Composer project: "composer": true in the manifest compiles the project's
# autoload dirs AND every package in composer.lock — vendor/ is source, not an
# autoload map evaluated at runtime.
```

Everything else is inspection — every stage of the pipeline is dumpable:

| Command | Purpose |
|---|---|
| `compile <file> -o <out>` | PHP source → native binary (file arg or stdin) |
| `build [manticore.json]` | build all manifest targets (libraries + applications) |
| `analyze <file>` | the static checks a compiler can make and an interpreter never gets to |
| `dump-ast` / `dump-mir` / `dump-llvm-mir` | parse / typed MIR / LLVM IR |
| `dump-llvm` | LLVM IR from stdin |
| `dump-sig <files>` | the module interface (exported symbol table) |
| `split-ir` | split a staged `.ll` module into N parts (dev tool behind `build -j`) |
| `version` / `help` | — |

Flags: `-o <out>`, `-O<0|1|2|3|s|z>` (clang opt level, default `-O2`),
`--emit-library` (a standalone `.o` with no `@main`), `--memory=rc|arena|hybrid`, and
`--prelude` / `--effects` for the `dump-*` commands. `compile` **analyzes by default** and
prints warnings without failing the build — `--no-analyze` turns it off, `--analyze-strict`
makes error-severity findings fail the compile (rc=65). `analyze` adds `--deep` (also run the
MIR type passes), `--json`, and `--baseline` / `--generate-baseline` to suppress known
findings. `--backend=<mir|ast>` is still parsed but inert — MIR is the only backend.
`build` also takes `--libs-only` (build the library targets and stop) and `--keep-ir`
(leave the generated `<output>.dbg.ll` / `.dbg.o` next to the target instead of staging
them in `/tmp` and deleting them — pair with `-O0` to get a binary lldb can walk).

⚠ The `dump-*` commands do **not** link the stdlib, so a call into it resolves as
`unknown`. When that matters, read the final binary.

## The PHP you get

Classes / interfaces / traits / enums (enum methods, constants, interface-implementing
enums), abstract + anonymous classes, late static binding (`new static`,
`static::method()`, `parent::`/`self::` forwarding), magic methods
(`__get`/`__set`/`__call`/`__invoke`/`__clone`/`__destruct`), `clone`-with, 8.4
**property hooks** + asymmetric visibility, closures + first-class callable syntax
(`f(...)`), by-ref / variadic params + argument unpacking, dynamic callables, the pipe
operator `|>`, `match`, DNF types, `??` / `?->`, `global` / `static` locals, heredoc /
nowdoc, string interpolation, constants, generators (`yield` / `yield from`),
exceptions with `try`/`catch`/`finally` and real stack traces, references to an array
element / object property and out-parameter auto-vivification
(`preg_match($re, $s, $m)`), reference returns, attributes, Reflection, Fibers, and
file I/O over libc.

**Generics** are docblock-driven, so the source stays valid PHP: `@template` with
bounds and defaults, `@extends`/`@implements`, generic traits (zero-cost), and
**reified** `@var Box<float> = new Box` — a real specialized class, no boxing — plus
implicit monomorphization of erased `array` / `callable` params
([`docs/generics.md`](docs/generics.md)).

**Standard library:** the `array_*` family in full, strings (incl. the whole `preg_*`
family over host PCRE2), type/reflection, math, `ctype_*`, JSON, `var_dump`/`print_r`,
SPL, date/time, sockets and streams, hashing and crypto. Each function is either a
PHP-level stdlib function (`src/Runtime/Stdlib/`, compiled into
`lib/manticore_stdlib.o` and auto-linked), an injected prelude helper, or an inlined
codegen builtin. No imports, no registration — they are simply there. The exact
name-by-name coverage per extension — and everything Manticore adds beyond PHP —
is generated into [`docs/builtins.md`](docs/builtins.md) (`php tools/builtins_audit.php`).

Current gaps are tracked with repros in [`docs/ROADMAP.md`](docs/ROADMAP.md); the
headline ones are listed under [Limitations](#limitations).

## Beyond PHP — the superset

Parity is the north star and `tools/difftest.sh` enforces it. The rest — the surface
`php` cannot run, and difftest therefore cannot check — is catalogued in
**[`docs/superset.md`](docs/superset.md)**: concurrency, compile-time attributes, FFI,
the module system, the type system, the memory model.

The headline is **structured concurrency** — Go's model, PHP's spelling, written in
PHP over two primitives of ours (native `Fiber` on `fcontext`, and `Io\Poll` over
kqueue/epoll):

```php
Async\async(function () {
    $a = Async\spawn(fn() => file_get_contents('https://example.com/one'));
    $b = Async\spawn(fn() => file_get_contents('https://example.com/two'));
    [$x, $y] = Async\awaitAll($a, $b);      // ~1 RTT, not 2
});
```

Ordinary `fread`/`fwrite`/`stream_socket_accept`/`sleep` suspend the fiber instead of
the process — plain streams *are* the async API, TLS and DNS included. Every task is
owned by a scope, cancellation is delivered at the suspend point, a deadlock is
reported rather than exited, and `Async\dump()` names every live task and where it was
spawned. An 8-worker prefork HTTP server does **150–160k rps** (`wrk`, plaintext
keep-alive). See [`docs/async.md`](docs/async.md).

**Native libraries** (zlib, libcurl, …) bind through FFI — `#[Library, Symbol]`
attributes compile to direct C calls, and `#[Library]` is what puts the library on the
link line; mechanism and C-type vocabulary in [`docs/ffi.md`](docs/ffi.md). The **module system**
([`docs/modules.md`](docs/modules.md)) is a cargo-style `manticore.json` with
`applications` and `libraries`, `.sig` module interfaces so a dependent target resolves
cross-unit calls without re-parsing sources, and a distributable compiler that ships
`bin/` + `lib/` with no PHP sources at all.

## Performance

Native AOT output vs the Zend interpreter on an Apple M1 Pro, `-O2`, PHP 8.5.10.
Each ordinary-PHP case is verified byte-for-byte against `php` before timing; loops
are data-dependent and `$argc`-seeded so LLVM cannot fold them away. Times are seconds
(lower is better); RSS is peak resident memory in MiB. Reproduce with
`REPS=5 bash bench/run.sh` (the script defaults to 3 runs; cases live in `bench/cases/`).

| Case | Native (s) | PHP (s) | Speedup | Native RSS (MiB) | PHP RSS (MiB) | Parity |
|---|---:|---:|---:|---:|---:|---|
| `alloc_churn` | 0.04 | 0.34 | 8.5× | 2.2 | 28.1 | ok |
| `array` | 0.08 | 0.91 | 11.4× | 7.1 | 36.0 | ok |
| `assoc` | 0.06 | 0.26 | 4.3× | 2.3 | 28.2 | ok |
| `assoc_small` | 0.03 | 0.18 | 6.0× | 2.0 | 27.9 | ok |
| `closures` | 0.03 | 0.63 | 21.0× | 2.1 | 28.0 | ok |
| `crc32` | 0.03 | 0.25 | 8.3× | 2.1 | 28.0 | ok |
| `dijkstra` | 0.02 | 0.34 | 17.0× | 2.5 | 29.2 | ok |
| `explode` | 0.06 | 0.39 | 6.5× | 2.0 | 28.0 | ok |
| `fib` | 0.11 | 12.09 | 109.9× | 2.0 | 28.1 | ok |
| `fiber_pingpong` | 0.03 | 0.36 | 12.0× | 2.2 | 27.9 | ok |
| `fiber_switch` | 0.06 | 0.56 | 9.3× | 2.2 | 27.9 | ok |
| `foreach_assoc` | 0.02 | 0.19 | 9.5× | 26.3 | 41.7 | ok |
| `funcarr` | 0.02 | 1.02 | 51.0× | 2.4 | 28.0 | ok |
| `generator_yield` | 0.02 | 0.71 | 35.5× | 2.0 | 28.0 | ok |
| `http_parse` | 1.08 | — | — | 3.0 | — | php-skip |
| `http_scale` | 1.17 | — | — | 3.2 | — | php-skip |
| `htmlspecialchars` | 0.09 | 0.41 | 4.6× | 2.2 | 28.0 | ok |
| `implode_int` | 0.20 | 0.28 | 1.4× | 2.2 | 28.3 | ok |
| `in_array` | 0.11 | 0.26 | 2.4× | 2.0 | 28.3 | ok |
| `json` | 0.08 | 0.25 | 3.1× | 2.2 | 28.3 | ok |
| `json_decode` | 0.11 | 0.30 | 2.7× | 16.0 | 45.9 | ok |
| `json_decode_object` | 0.02 | 0.16 | 8.0× | 6.4 | 33.9 | ok |
| `json_decode_records` | 0.07 | 0.24 | 3.4× | 11.1 | 39.0 | ok |
| `json_deep` | 0.03 | 0.18 | 6.0× | 2.2 | 28.6 | ok |
| `json_escape_heavy` | 0.02 | 0.15 | 7.5× | 3.4 | 28.6 | ok |
| `json_objects` | 0.21 | 0.36 | 1.7× | 5.0 | 29.5 | ok |
| `json_pretty` | 0.08 | 0.16 | 2.0× | 5.0 | 29.5 | ok |
| `json_records` | 0.34 | 0.63 | 1.9× | 18.9 | 33.4 | ok |
| `json_utf8` | 0.06 | 0.25 | 4.2× | 6.3 | 29.4 | ok |
| `ksort_asort` | 0.02 | 0.13 | 6.5× | 6.2 | 29.1 | ok |
| `loop` | 0.06 | 1.37 | 22.8× | 2.0 | 27.9 | ok |
| `mandelbrot` | 0.04 | 0.97 | 24.2× | 2.0 | 28.0 | ok |
| `mathf` | 0.02 | 0.72 | 36.0× | 2.0 | 27.9 | ok |
| `matmul` | 0.01 | 0.17 | 17.0× | 2.6 | 28.9 | ok |
| `nbody` | 0.04 | 0.37 | 9.2× | 2.0 | 28.2 | ok |
| `nested_array_local` | 0.02 | 0.18 | 9.0× | 2.2 | 28.2 | ok |
| `net_bulk` | 0.01 | 0.14 | 14.0× | 2.9 | 28.1 | ok |
| `net_lines` | 0.02 | 0.15 | 7.5× | 5.9 | 28.2 | ok |
| `oop` | 0.08 | 3.18 | 39.8× | 2.0 | 28.2 | ok |
| `refslot` | 0.00 | 0.13 | ∞ | 2.5 | 28.5 | ok |
| `sieve` | 0.03 | 0.44 | 14.7× | 28.1 | 58.7 | ok |
| `sort` | 0.05 | 0.20 | 4.0× | 2.4 | 28.1 | ok |
| `spectralnorm` | 0.02 | 1.55 | 77.5× | 2.2 | 27.9 | ok |
| `sprintf` | 0.04 | 0.19 | 4.8× | 2.0 | 27.9 | ok |
| `strcat` | 0.14 | 0.80 | 5.7× | 32.4 | 59.5 | ok |
| `strops` | 0.02 | 0.22 | 11.0× | 2.0 | 28.0 | ok |
| `tokenize` | 0.03 | — | — | 14.3 | — | php-skip |
| `unset_churn` | 0.00 | 0.13 | ∞ | 3.6 | 29.1 | ok |
| `variadic_pack` | 0.08 | 0.26 | 3.2× | 2.5 | 28.0 | ok |
| `wordcount` | 0.02 | 0.16 | 8.0× | 2.0 | 28.1 | ok |

All 47 comparable cases are faster natively; three HTTP/tokenization cases are
native-only because they use Manticore prelude APIs. The sub-10 ms values are at the
harness's two-decimal precision, so treat their speedups as directional. The table also
shows the start-up-memory advantage: most native binaries stay near 2–3 MiB RSS, while
the PHP interpreter baseline is roughly 28 MiB before workload-specific allocations.

## Examples

The runnable demos live in [`examples/`](examples/): [`async/`](examples/async/) covers
structured concurrency and [`http/`](examples/http/) has native HTTP servers. The
[`symfony-console/`](examples/symfony-console/) example is a small Composer application
compiled with Symfony Console itself — install its dependencies, then build the project:

```bash
cd examples/symfony-console
composer install
manticore build
./bin/demo greet Ada
```

Its manifest uses `"composer": true`, so Composer autoload roots and installed packages
are compiled as source; the generated binary does not load `vendor/autoload.php` at runtime.

## How it is built

```
PHP source
  → Lexer            (src/Lexer)         tokens
  → Parser           (src/Parser)        AST  (recursive-descent + Pratt)
  → LowerFromAst     ─┐
  → ConstFold         │
  → DeadStore         │
  → InferTypes        │  MIR (src/Compile/Mir) — flat, typed, SSA-ish IR.
  → VivifyRefArgs     │  The only backend. InferTypes re-runs after each pass
  → NarrowReturns     │
  → InlineClosures    │  that makes new types concrete, which is why
  → Monomorphize      │  Monomorphize — specializing erased-array and callable
  → FuseSplitJoin     │  params per call-site shape — sits this far down.
  → TypeCheck         │
  → NarrowReturns     │  Full annotation: src/Compile/README.md
  → CheckTypeDefs     │
  → ReflectAnalysis   │
  → DemoteCharLocals  │
  → InferEffects      │
  → InferAllocKind    │
  → ApplyMemoryMode   │
  → InsertMemoryOps   │  (rc retain/release/CoW insertion)
  → Verify           ─┘
  → EmitLlvm          (src/Compile/Mir/Passes/EmitLlvm*) → LLVM IR text
  → clang -c          IR → object
  → cc                link (libc + pcre2 + openssl, + FFI-bound libraries on demand)
```

**Memory** ([`docs/memory.md`](docs/memory.md)): reference counting on strings,
objects, vecs and assoc arrays with copy-on-write, so frees are deterministic and there
are no GC pauses; a synchronous Bacon–Rajan cycle collector that is opt-in and
zero-overhead until `gc_collect_cycles()` is reached; and three allocation modes
(`--memory` / `MANTICORE_MEMORY`) — `hybrid` (default, escape analysis routes each
allocation between arena and heap-rc), `rc`, `arena`.

**Source layout** — pure PHP, one class per file, path mirrors FQN:

```
bin/            build & run scripts + the output binary
  compile         cold seed (Zend → throwaway seed → native compiler + stdlib)
  build           self-host rebuild via the manifest (+ --seed, --verify)
  manticore-install  the installer entry point Composer exposes
lib/            prebuilt stdlib object + .sig + prelude (build artifacts, gitignored)
prelude/        PHP injected into every program (Fiber, async runtime, Resource, …)
src/Lexer/      tokenizer
src/Parser/     recursive-descent + Pratt parser; AST node types
src/Compile/    AST → MIR lowering, MIR passes, EmitLlvm backend
src/Codegen/    low-level LLVM-IR text builders (no semantic logic)
src/Analyze/    the static analyzer behind `analyze` and compile-time warnings
src/Cli/        subcommand registry + argument parsing
src/Ffi/        the attributes and opaque pointer type for native bindings
src/Runtime/    PHP-level stdlib + libc / OS / FFI bindings compiled into binaries
src/Manticore/  driver (Main.php), Sig.php, the build command
tools/          build + gate scripts (selfhost, difftest, docker, …)
tests/aot/      the harness: cases/*.php + expected/*.out, auto-discovered
examples/       runnable demos (examples/async/ has the concurrency ones)
docs/           the guides; docs/ROADMAP.md is the status + gap matrix
```

## Gates

```bash
bash tests/aot/run.sh                 # the AOT suite (cases/ + expected/, auto-discovered)
bash tests/aot/run.sh -k hello        # filter by substring
bash tools/difftest.sh                # parity vs `php`
bash tools/selfhost_fixpoint.sh       # fixpoint + self-host suite + rebuild stability
bash tools/docker/run_tests.sh --gate # the same, on Linux
```

`selfhost_fixpoint.sh` asserts gen2 IR == gen3 IR, runs the suite through the
self-built compiler, and rebuilds repeatedly to catch build-to-build layout roulette.
The Linux gate is not optional for anything touching `src/Runtime/`, syscalls or errno.

## Limitations

- **Integer overflow wraps** (two's-complement) instead of promoting to float —
  `PHP_INT_MAX + 1` gives `PHP_INT_MIN`.
- **`extract()`** is not implemented (dynamic symbol-table writes the typed frame does
  not model). `compact()` works.
- **`goto` into a loop body** is unsupported (plain forward/backward `goto` works).
- **Cycle collector** is manual-trigger only and does not scan static/global roots.
- **`trait`s and generic classes do not cross a compiled-library boundary.**
  Classes, interfaces, enums and constants do (`.sig` schema 2); a trait and a
  `@template` class both need their method bodies on the far side.
- **Regular-file I/O blocks the async loop** by design; see
  [`docs/async.md`](docs/async.md#-what-is-not-async) for the measurements and
  `Async\readFile()`.

The full, current gap matrix with repros lives in
[`docs/ROADMAP.md`](docs/ROADMAP.md).

## License

Licensed under the [MIT License](LICENSE).

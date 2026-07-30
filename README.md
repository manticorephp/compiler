# Manticore

Self-hosted PHP-to-native AOT compiler. Compiles a large subset of PHP 8.5+ to
standalone native binaries (arm64 / x86_64) through LLVM IR — no PHP runtime, no
shared libraries beyond libc. **The compiler is written in PHP and compiles itself
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

**Emitted binaries need nothing but libc.** The *compiler* needs a real toolchain on
the host, because it ends in `clang` and `cc`:

| What | Version | Why |
|---|---|---|
| `clang` + `cc` on `PATH` | **LLVM ≥ 15** | Manticore emits opaque-pointer IR; clang 14 rejects it |
| `php` | **8.5** | cold bootstrap only — Zend runs the compiler source once to seed the first native binary |
| libpcre2 (**dev** package) | 10.x | `preg_*` rides host PCRE2; needs `pcre2-config` |
| OpenSSL 3 (**dev** package) | 3.x | TLS, `hash`/`hmac`; needs `pkg-config` |

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

manticore version        # manticore 0.6.0
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
| `version` / `help` | — |

Flags: `-o <out>`, `-O<0|1|2|3|s|z>` (clang opt level, default `-O2`),
`--emit-library` (a standalone `.o` with no `@main`), `--memory=rc|arena|hybrid`, and
`--prelude` / `--effects` for the `dump-*` commands. `compile` **analyzes by default** and
prints warnings without failing the build — `--no-analyze` turns it off, `--analyze-strict`
makes error-severity findings fail the compile (rc=65). `analyze` adds `--deep` (also run the
MIR type passes), `--json`, and `--baseline` / `--generate-baseline` to suppress known
findings. `--backend=<mir|ast>` is still parsed but inert — MIR is the only backend.

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
codegen builtin. No imports, no registration — they are simply there.

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
attributes compile to direct C calls, declared as manifest `extensions`; mechanism and
type mapping in [`docs/ffi.md`](docs/ffi.md). The **module system**
([`docs/modules.md`](docs/modules.md)) is a cargo-style `manticore.json` with
`applications` and `libraries`, `.sig` module interfaces so a dependent target resolves
cross-unit calls without re-parsing sources, and a distributable compiler that ships
`bin/` + `lib/` with no PHP sources at all.

## Performance

Native AOT output vs the Zend interpreter, Apple M1 Pro, `-O2`, best of 5 (`REPS=5`; the
script defaults to 3), every compiled program verified byte-equal to `php` first. Loops are
data-dependent and `$argc`-seeded so LLVM cannot fold them away. Representative slice —
reproduce with `REPS=5 bash bench/run.sh` (cases in `bench/cases/`):

| Benchmark | Workload | `php` | manticore | Speedup |
|---|---|---:|---:|---:|
| `spectralnorm` | float math, tight loops | 0.44 s | 0.01 s | **44×** |
| `oop` | 20 M polymorphic virtual calls | 1.13 s | 0.04 s | **28×** |
| `fib` | recursive, data-dependent depth | 1.86 s | 0.10 s | **19×** |
| `sieve` | Eratosthenes to 2 M | 0.20 s | 0.02 s | **10×** |
| `funcarr` | `array_map`/`filter`/`reduce` | 0.16 s | 0.02 s | **8.0×** |
| `strcat` | 30 M-iter string append | 0.37 s | 0.11 s | **3.4×** |
| `sort` | `sort()` 3 K ints × 200 | 0.12 s | 0.05 s | **2.4×** |
| `json` | `json_encode` loop | 0.12 s | 0.08 s | **1.5×** |

Compute-bound work wins big (no per-op dispatch). The library-bound tail is the
tightest race — those helpers compete with PHP's hand-tuned C — and native still wins
every one. Also: **cold start** 2.6 ms vs 62 ms (~24×), a trivial program links to a
**~50 KB** fully-static binary, and `fib.php` compiles to native in ~0.1 s.

## How it is built

```
PHP source
  → Lexer            (src/Lexer)         tokens
  → Parser           (src/Parser)        AST  (recursive-descent + Pratt)
  → LowerFromAst     ─┐
  → ConstFold         │
  → DeadStore         │
  → InferTypes        │  MIR (src/Compile/Mir) — flat, typed, SSA-ish IR.
  → NarrowReturns     │  The only backend. InferTypes re-runs after each pass
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
  → cc                link static binary (libc only)
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
- **A `.sig` carries functions only** — classes, interfaces, traits, enums and
  constants do not yet cross a compiled-library boundary.
- **Regular-file I/O blocks the async loop** by design; see
  [`docs/async.md`](docs/async.md#-what-is-not-async) for the measurements and
  `Async\readFile()`.

The full, current gap matrix with repros lives in
[`docs/ROADMAP.md`](docs/ROADMAP.md).

## License

Licensed under the [MIT License](LICENSE).

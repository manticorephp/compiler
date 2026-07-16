# Docker test setup

Two tiers. Tier 1 asks what the libc under manticore actually looks like on
Linux. Tier 2 tries to build the compiler there and run the whole AOT suite.

Everything here is re-runnable and writes nothing to the host checkout.

## Tier 1 -- libc probe

```bash
bash tools/docker/probe_libc.sh              # arm64 (native on Apple Silicon)
bash tools/docker/probe_libc.sh --amd64      # arm64 + amd64 (amd64 via qemu)
bash tools/docker/probe_libc.sh --amd64-only
```

Covers ubuntu:20.04 / 22.04 / 24.04, debian:12 and alpine:3.20 (musl). Per
image it reports, for the symbols `src/Runtime/Libc.php` binds by name, whether
each is an exported dynamic symbol (`readelf --dyn-syms` on the libc object),
then compiles and RUNS `probe.c` in the container to read the real constants and
`struct stat` / `struct dirent` / `glob_t` layout out of that libc's headers.

Results land in **`PROBE_RESULTS.md`** (committed). Raw per-image dumps go to
`probe-raw/` (gitignored). Raw output accumulates across runs keyed by
image+arch, so an amd64 sweep does not discard an earlier arm64 one -- the
rendered table wants both.

Re-render without re-probing:

```bash
php tools/docker/render_results.php tools/docker/probe-raw > tools/docker/PROBE_RESULTS.md
```

`render_results.php` also VALIDATES the hard-coded ABI table in
`src/Runtime/Stdlib/Stat.php` against the measured layout, per target. If that
table ever drifts from a real libc, the "Answers" section says so instead of
printing MATCH.

### Headline findings

- Manticore binds plain `stat`/`lstat`/`fstat`. **glibc < 2.33 does not export
  them** -- only `__xstat`/`__lxstat`/`__fxstat`. Ubuntu 20.04 (glibc 2.31)
  therefore cannot link; 22.04 / Debian 12 / Alpine can.
- musl exports plain `stat`/`lstat`/`fstat` AND has `glob`/`globfree`, but
  lacks `GLOB_BRACE`, `GLOB_ONLYDIR` and the LFS64 aliases (`stat64`).
- The `struct stat` layout is a kernel/arch ABI: identical across every glibc
  version probed and musl. Both Linux branches of `Stat.php` match.

## Tier 2 -- build + run the suite

```bash
bash tools/docker/run_tests.sh            # arm64
bash tools/docker/run_tests.sh --amd64    # amd64 (emulated, slow)
bash tools/docker/run_tests.sh --both
bash tools/docker/run_tests.sh --shell    # interactive container
```

`Dockerfile.debian` carries **PHP 8.5** (sury.org) and the **latest stable
clang** (apt.llvm.org, currently 21) on board, deliberately -- Debian bookworm's
stock php 8.2 and clang 14 are both unusable here:

- PHP 8.5 is manticore's target language, so the Zend seed must be 8.5.
- clang 14 predates LLVM 15's opaque pointers and **rejects the IR manticore
  emits** (`ptr type is only supported in -opaque-pointers mode`). Verified.

The repo is bind mounted **read-only** at `/repo` and copied to a scratch dir in
the container. This is not incidental: `bin/compile` writes `bin/manticore` and
`lib/`, and the host checkout is macOS -- a read-write mount would overwrite the
host's binaries with Linux ones. The copy is also wiped of any stale
`bin/manticore` + `lib/` first, since a stale object fakes a pass.

`bin/compile` is never piped: it is redirected to a log. `set -euo pipefail`
would report `tail`'s exit code and hide a failed build.

### Current state: the build does NOT complete on Linux

Two blockers, in order.

**(a) `bin/compile` stage [3/5] is macOS-only.** It stubs undefined symbols by
scraping the linker's error text with `grep '^  "_'`, which matches Apple ld's
format only:

```
  "_pcre2_compile_8", referenced from:
```

GNU ld reports the same condition as:

```
mir:(.text+0x31cb64): undefined reference to `pcre2_compile_8'
```

so on Linux `stubs.c` comes out EMPTY and stage [4/5] fails with the seven
`pcre2_*_8` references it was supposed to stub. `patch_compile_for_gnu_ld.php`
fixes the extraction to accept both linkers; `run_tests.sh` applies it **to the
container's copy only** (`PATCH_LD=0` to see the unpatched failure). The real
fix belongs in `bin/compile` and is not applied here -- this tree only touches
`tools/docker/`.

**(b) With (a) patched, the seed SIGSEGVs building the stdlib.** Stages 1-4
pass, then stage [5/5] crashes:

```
build: library 'stdlib' (src/Runtime -> lib/manticore_stdlib.o)
bin/compile: line 81: 43 Segmentation fault  "$SEED" build "$MANIFEST"
```

Backtrace (gdb, arm64):

```
#0  __mir_strlen ()
#1  __mir_str_eq ()
#2  manticore_Compile_Mir_Passes_EmitLlvm__unboxCellToType ()
#3  manticore_Compile_Mir_Passes_EmitLlvm__unboxCellArg ()
#4  manticore_Compile_Mir_Passes_EmitLlvm__emitCall ()
...
#17 manticore_Manticore_build_compile_module ()
#18 manticore_Manticore_cmd_build ()
```

Scoped, so it is not "Linux is broken":

- The Linux seed compiles and RUNS a hello-world correctly.
- It is not a stubbing artifact: only the seven `pcre2_*_8` symbols get stubbed
  on Linux, which is correct (the seed does not need preg).
- A program using `sort()`/`implode()` fails only with
  `use of undefined value '@manticore_array_values'` -- a consequence of
  `lib/manticore_stdlib.o` never being built, not a separate bug.

So one crash in `EmitLlvm::unboxCellToType` gates the whole Linux port, and the
AOT suite has not yet run on Linux.

## Files

| file | role |
|---|---|
| `probe_libc.sh` | Tier 1 driver: runs the containers, renders the report |
| `probe_in_container.sh` | Tier 1 in-container half (`/bin/sh`, dependency-free) |
| `probe.c` | prints constants + struct layout from the container's own headers |
| `render_results.php` | raw dumps -> `PROBE_RESULTS.md`, validates `Stat.php` |
| `PROBE_RESULTS.md` | generated report (committed) |
| `Dockerfile.debian` | Tier 2 image: php 8.5 + latest clang + libpcre2-dev |
| `run_tests.sh` | Tier 2 driver: build in container, run the full AOT suite |
| `patch_compile_for_gnu_ld.php` | diagnostic patch for blocker (a), container copy only |

The only scripting languages here are bash, php and C -- no python, matching the
rest of the repo. Tier 1 renders on the host's `php`; the Tier 2 patch runs on
the container's php 8.5.

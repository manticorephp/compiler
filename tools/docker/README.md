# Docker test setup

Builds the compiler on Linux and runs the whole AOT suite there. Re-runnable,
and writes nothing to the host checkout.

## libc findings -- `PROBE_RESULTS.md`

`PROBE_RESULTS.md` is a **committed record of a one-off measurement**: what the
libc under manticore actually looks like on ubuntu:20.04 / 22.04 / 24.04,
debian:12 and alpine:3.20 (musl), across arm64 and x86_64. For each symbol
`src/Runtime/Libc.php` binds by name it records whether that libc exports it,
plus the real constants and `struct stat` / `struct dirent` / `glob_t` layout
read out of the container's own headers.

It is kept because **`src/` hard-codes those numbers** (`Net.php`, `Stat.php`,
`Fs.php`, `LowerPrelude.php` all cite it). The rig that generated it was removed
once it had done its job -- recover it from git history if you need to
re-measure, e.g. before changing one of those ABI tables.

### Headline findings

- Manticore binds plain `stat`/`lstat`/`fstat`. **glibc < 2.33 does not export
  them** -- only `__xstat`/`__lxstat`/`__fxstat`. Ubuntu 20.04 (glibc 2.31)
  therefore cannot link; 22.04 / Debian 12 / Alpine can.
- musl exports plain `stat`/`lstat`/`fstat` AND has `glob`/`globfree`, but
  lacks `GLOB_BRACE`, `GLOB_ONLYDIR` and the LFS64 aliases (`stat64`).
- The `struct stat` layout is a kernel/arch ABI: identical across every glibc
  version probed and musl. Both Linux branches of `Stat.php` match.

## Build + run the suite

```bash
bash tools/docker/run_tests.sh            # arm64: cached self-host build + full suite
bash tools/docker/run_tests.sh --amd64    # amd64 (emulated, slow)
bash tools/docker/run_tests.sh --both
bash tools/docker/run_tests.sh --alpine   # musl (Alpine) instead of glibc — a CI row too
bash tools/docker/run_tests.sh --shell    # interactive container
bash tools/docker/run_tests.sh --gate     # the HEAVY gate, on Linux
bash tools/docker/run_tests.sh -k http_    # one case (or a substring)
bash tools/docker/run_tests.sh --cold     # ignore the cache: build from the pinned release
```

`--gate` adds `tools/difftest.sh` (php is in the image) and
`tools/selfhost_fixpoint.sh` (fixpoint, self-host suite, MIR golden,
rebuild-stability) after the suite, and exits non-zero if any of the three fails.
That is the only honest gate for anything touching the epoll path, the Linux
socket/errno constants or a glibc `free()` — macOS green proves nothing about them
(see the invalid-free that only glibc caught). Stability defaults to 2x2 rebuilds
in a container because each cold build from the pin is minutes; `MC_STABILITY_N=5` for the full
sweep.

The image is the **root `Dockerfile`'s `toolchain` target** (or
`Dockerfile.alpine`'s, with `--alpine`) -- the same one an end user builds (see
`docs/install.md`). Each libc gets its own image tag and its own compiler-cache
volume: a glibc binary does not run in a musl container, and a shared cache would
hand the gate a compiler the loader refuses. It carries **PHP 8.5** (sury.org) and
the **latest stable clang** (apt.llvm.org, currently 22) on board, deliberately
-- Debian's stock php and clang are both unusable here:

- PHP 8.5 is manticore's target language, so the difftest oracle must be 8.5.
- clang 14 predates LLVM 15's opaque pointers and **rejects the IR manticore
  emits** (`ptr type is only supported in -opaque-pointers mode`). Verified.

The repo is bind mounted **read-only** at `/repo` and copied to a scratch dir in
the container. This is not incidental: a build writes `bin/manticore` and `lib/`,
and the host checkout is macOS -- a read-write mount would overwrite host binaries
with Linux ones. The copy is wiped of host artifacts first, then the runner restores
a self-hosted Linux compiler from an architecture-specific Docker volume when its
architecture, PHP version, and clang version match. It rebuilds the current sources
with that compiler and refreshes the volume. A missing, incompatible, or failing
cache falls back to the pinned release (`BOOTSTRAP_VERSION`, fetched by
`tools/fetch_bootstrap.sh`); use `--cold` to force that path. A tree the pin cannot
build fails with a bootstrap-gap error naming both versions.

The build is never piped: it is redirected to a log. `set -euo pipefail`
would report `tail`'s exit code and hide a failed build.

### Current state

The Linux build (arm64 and amd64, glibc and musl) self-hosts from the pinned
release and passes the AOT suite in this image, non-root. The earlier
port-time blockers (a macOS-only linker-error scraper, FFI-wrapper linkage,
missing `-lm`, an uninitialised exception runtime) are fixed; the history is in
git. `tools/link_stubs.sh` is now the one implementation of the undefined-symbol
stubbing shared with `tools/selfhost.sh`.

## Files

| file | role |
|---|---|
| `run_tests.sh` | driver: build in a container, run the full AOT suite (`--gate` for the full gate) |
| `PROBE_RESULTS.md` | committed libc measurements that `src/` hard-codes from |
| `iopoll/` | a second, self-contained harness for the epoll/kqueue reactor: its own `Dockerfile`, `run.sh`, `in_container.sh`, `diag.sh` and `cases/` |

The main image is the root `Dockerfile` (`--target toolchain`), not a file here —
one image definition serves both users and this harness. `iopoll/` is the
exception: it ships its own minimal Dockerfile because it tests the reactor
against a specific kernel surface rather than the whole toolchain.

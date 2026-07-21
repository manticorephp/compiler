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
bash tools/docker/run_tests.sh            # arm64
bash tools/docker/run_tests.sh --amd64    # amd64 (emulated, slow)
bash tools/docker/run_tests.sh --both
bash tools/docker/run_tests.sh --shell    # interactive container
```

The image is the **root `Dockerfile`'s `toolchain` target** -- the same one an
end user builds (see `docs/install.md`). It carries **PHP 8.5** (sury.org) and
the **latest stable clang** (apt.llvm.org, currently 21) on board, deliberately
-- Debian bookworm's stock php 8.2 and clang 14 are both unusable here:

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

### Current state: the Linux build is GREEN

`bin/compile` cold-seeds, self-hosts (`bin/build`, fixpoint OK), and passes the
full AOT suite on Linux (arm64) in this image, non-root. Two rounds of blockers
got it there; both are fixed.

**(a) `bin/compile` stage [3/5] was macOS-only -- FIXED (issue #1).** It stubbed
undefined symbols by scraping the linker's error text with `grep '^  "_'`, which
matches Apple ld's format only:

```
  "_pcre2_compile_8", referenced from:
```

GNU ld reports the same condition differently, and *in the user's language*:

```
mir:(.text+0x31cb64): undefined reference to `pcre2_compile_8'
mir:(.text+0x31cb64): référence indéfinie vers « pcre2_compile_8 »   # fr_FR
```

so on Linux `stubs.c` came out EMPTY and stage [4/5] failed with the seven
`pcre2_*_8` references it was supposed to stub. The same broken snippet was
copy-pasted into `bin/compile`, `tools/selfhost.sh` and `tools/link_stubs.sh`.

Now there is **one** implementation, `tools/link_stubs.sh`, which the other two
call. It probes the linker under `LC_ALL=C` (killing the localized-message class
of failure at the source rather than matching translations), understands Apple
ld / GNU ld / lld, and **fails loudly** if the linker reported errors but no
symbols were extracted -- the silent-empty-`stubs.c` mode is what made this bug
surface one stage later than its cause.

**(b) stage [5/5] failures -- FIXED.** This section once blamed a SIGSEGV in
`EmitLlvm::unboxCellToType`; that crash was already gone when the port resumed
(fixed upstream by repr-consistency work). The genuine Linux-only blockers were
three linkage/runtime issues -- the emitted IR is byte-identical across
platforms, so none were visible from the IR alone; only GNU ld vs Apple ld64 and
glibc vs BSD libc differed:

- **FFI-wrapper linkage.** Prelude libc wrappers (`__mc_libc_*`, declared in
  `prelude/resource.php`) were emitted with external linkage into *every* module,
  so a user `.o` and the prebuilt `stdlib.o` both defined them -> GNU ld
  "multiple definition" (Apple ld64 silently coalesces). Non-prelude bindings
  (`Runtime\Libc\*`), by contrast, are defined once and referenced across the
  `.o` boundary, so `--gc-sections` drops a linkonce copy. Fix: `linkonce_odr`
  for *prelude* FFI wrappers, plain external for the rest (mirrors
  `EmitLlvmModule`'s per-function `isPrelude` linkage).
- **Missing `-lm`.** glibc/musl keep libm in a separate archive (Darwin folds it
  into libSystem), so `tanh`/`pow`/… went undefined. Fix: append `-lm` on the
  non-Darwin link.
- **Uninitialised exception runtime.** A program that never `throw`s in its own
  code but links `stdlib.o` (which can) left `@main`'s `depth:=1` + base landing
  pad unemitted -- both were gated on the *caller* module's `needsExceptions`. A
  stdlib throw then read an uninitialised depth 0, computed slot `0-1 = -1`, and
  the out-of-range guard fatally reported "Maximum try nesting" (it fires on
  slot < 0 too). Fix: force `needsExceptions` on for every non-library module.

Plus a container-faithfulness fix so the image matches a real deployment: add
`netbase` (`/etc/services`, `/etc/protocols`) and run as a non-root user (root
bypasses DAC checks, so `is_writable()` of a `0400` file wrongly returns true).
Root-cause detail and the debugging trail are in the git history / linux-port
notes.

## Files

| file | role |
|---|---|
| `run_tests.sh` | driver: build in a container, run the full AOT suite |
| `PROBE_RESULTS.md` | committed libc measurements that `src/` hard-codes from |

The image is the root `Dockerfile` (`--target toolchain`), not a file here --
one image definition serves both users and this harness.

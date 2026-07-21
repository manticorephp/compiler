# libc probe results

Every value below is output from a container, not an assumption: each was read
out of that libc's own headers by a C program compiled and run inside the image.

This is a **record of a one-off measurement**, kept because `src/` cites it --
the `struct addrinfo` / `struct stat` / `glob_t` offsets and the socket
constants in `src/Runtime/Stdlib/Net.php`, `Stat.php`, `Fs.php` and
`src/Compile/Mir/Passes/LowerPrelude.php` are hard-coded FROM these numbers.
Change one of those tables and you are contradicting a measurement; re-measure
first.

The probe rig that produced it (`probe.c`, `probe_libc.sh`,
`probe_in_container.sh`, `render_results.php`) was removed once it had done its
job -- recover it from git history if it is ever needed again.

## Answers

### 1. Plain `stat`/`lstat`/`fstat` vs `__xstat`/`__lxstat`/`__fxstat`

Exports plain `stat` (and `__xstat` too, for ABI compat):

- **alpine:3.20 amd64** -- musl libc (x86_64)
- **alpine:3.20 arm64** -- musl libc (aarch64)
- **debian:12 amd64** -- ldd (Debian GLIBC 2.36-9+deb12u14) 2.36
- **debian:12 arm64** -- ldd (Debian GLIBC 2.36-9+deb12u14) 2.36
- **ubuntu:22.04 amd64** -- ldd (Ubuntu GLIBC 2.35-0ubuntu3.13) 2.35
- **ubuntu:22.04 arm64** -- ldd (Ubuntu GLIBC 2.35-0ubuntu3.13) 2.35
- **ubuntu:24.04 amd64** -- ldd (Ubuntu GLIBC 2.39-0ubuntu8.7) 2.39
- **ubuntu:24.04 arm64** -- ldd (Ubuntu GLIBC 2.39-0ubuntu8.7) 2.39

Exports ONLY the `__xstat` family -- plain `stat` is **absent**:

- **ubuntu:20.04 amd64** -- ldd (Ubuntu GLIBC 2.31-0ubuntu9.17) 2.31
- **ubuntu:20.04 arm64** -- ldd (Ubuntu GLIBC 2.31-0ubuntu9.17) 2.31

The glibc 2.33 story is CONFIRMED, not assumed: every glibc >= 2.35 here
exports plain `stat`; glibc 2.31 (Ubuntu 20.04) does not, on both arches.
`__xstat` is present everywhere, including musl (as a compat alias).

**Consequence for manticore:** `src/Runtime/Libc.php` binds plain `stat`,
`lstat` and `fstat` by symbol name. On any glibc < 2.33 that link fails
with an undefined reference. Ubuntu 20.04 is out of reach without an
`__xstat`-based fallback; Ubuntu 22.04+/Debian 12+/Alpine are fine.

### 2. musl / Alpine

- **alpine:3.20 amd64**: plain `stat` PRESENT, `lstat` PRESENT, `fstat` PRESENT; `glob` PRESENT, `globfree` PRESENT; `stat64` ABSENT
- **alpine:3.20 arm64**: plain `stat` PRESENT, `lstat` PRESENT, `fstat` PRESENT; `glob` PRESENT, `globfree` PRESENT; `stat64` ABSENT

musl DOES export plain `stat`/`lstat`/`fstat`, and DOES have
`glob`/`globfree`. It also exports `__xstat`/`__lxstat`/`__fxstat` as
glibc-compat aliases. It does NOT export `stat64`/`readdir64` -- musl
1.2.4+ dropped the LFS64 aliases.

### 3. Constants

Every probed constant has the SAME value on every distro and both arches
(see the constants table), with two exceptions, both on musl:

- `GLOB_BRACE`: **NOT DEFINED** on alpine:3.20 amd64, alpine:3.20 arm64
- `GLOB_ONLYDIR`: **NOT DEFINED** on alpine:3.20 amd64, alpine:3.20 arm64

GLOB_BRACE being absent on musl is CONFIRMED. GLOB_ONLYDIR is absent on
musl too -- that one was not predicted.

### 4. `struct stat` layout vs the table in `src/Runtime/Stdlib/Stat.php`

| target | arch | Stat.php table vs measured layout |
|---|---|---|
| alpine:3.20 amd64 | x86_64 | MATCH |
| alpine:3.20 arm64 | aarch64 | MATCH |
| debian:12 amd64 | x86_64 | MATCH |
| debian:12 arm64 | aarch64 | MATCH |
| ubuntu:20.04 amd64 | x86_64 | MATCH |
| ubuntu:20.04 arm64 | aarch64 | MATCH |
| ubuntu:22.04 amd64 | x86_64 | MATCH |
| ubuntu:22.04 arm64 | aarch64 | MATCH |
| ubuntu:24.04 amd64 | x86_64 | MATCH |
| ubuntu:24.04 arm64 | aarch64 | MATCH |

Both Linux branches of `Stat.php` are validated against a real libc on
real hardware/emulation, including the previously unverified x86_64 one.
The layout is identical across glibc versions AND musl -- it is a
kernel/arch ABI, not a libc choice. `dirent.d_name` at 19 matches
`__mc_dirent_name_off()`'s non-Darwin value.

## Images

| target | os-release | arch | ldd --version | probe __GLIBC__ | libc object |
|---|---|---|---|---|---|
| alpine:3.20 amd64 | Alpine Linux v3.20 | x86_64 | musl libc (x86_64) | non-glibc (musl or other) | /lib/ld-musl-x86_64.so.1 |
| alpine:3.20 arm64 | Alpine Linux v3.20 | aarch64 | musl libc (aarch64) | non-glibc (musl or other) | /lib/ld-musl-aarch64.so.1 |
| debian:12 amd64 | Debian GNU/Linux 12 (bookworm) | x86_64 | ldd (Debian GLIBC 2.36-9+deb12u14) 2.36 | glibc 2.36 | /lib/x86_64-linux-gnu/libc.so.6 |
| debian:12 arm64 | Debian GNU/Linux 12 (bookworm) | aarch64 | ldd (Debian GLIBC 2.36-9+deb12u14) 2.36 | glibc 2.36 | /lib/aarch64-linux-gnu/libc.so.6 |
| ubuntu:20.04 amd64 | Ubuntu 20.04.6 LTS | x86_64 | ldd (Ubuntu GLIBC 2.31-0ubuntu9.17) 2.31 | glibc 2.31 | /lib/x86_64-linux-gnu/libc.so.6 |
| ubuntu:20.04 arm64 | Ubuntu 20.04.6 LTS | aarch64 | ldd (Ubuntu GLIBC 2.31-0ubuntu9.17) 2.31 | glibc 2.31 | /lib/aarch64-linux-gnu/libc.so.6 |
| ubuntu:22.04 amd64 | Ubuntu 22.04.5 LTS | x86_64 | ldd (Ubuntu GLIBC 2.35-0ubuntu3.13) 2.35 | glibc 2.35 | /lib/x86_64-linux-gnu/libc.so.6 |
| ubuntu:22.04 arm64 | Ubuntu 22.04.5 LTS | aarch64 | ldd (Ubuntu GLIBC 2.35-0ubuntu3.13) 2.35 | glibc 2.35 | /lib/aarch64-linux-gnu/libc.so.6 |
| ubuntu:24.04 amd64 | Ubuntu 24.04.4 LTS | x86_64 | ldd (Ubuntu GLIBC 2.39-0ubuntu8.7) 2.39 | glibc 2.39 | /lib/x86_64-linux-gnu/libc.so.6 |
| ubuntu:24.04 arm64 | Ubuntu 24.04.4 LTS | aarch64 | ldd (Ubuntu GLIBC 2.39-0ubuntu8.7) 2.39 | glibc 2.39 | /lib/aarch64-linux-gnu/libc.so.6 |

## Dynamic symbols (`readelf --dyn-syms` on the libc object)

| symbol | alpine:3.20 amd64 | alpine:3.20 arm64 | debian:12 amd64 | debian:12 arm64 | ubuntu:20.04 amd64 | ubuntu:20.04 arm64 | ubuntu:22.04 amd64 | ubuntu:22.04 arm64 | ubuntu:24.04 amd64 | ubuntu:24.04 arm64 |
|---|---|---|---|---|---|---|---|---|---|---|
| `stat` | yes | yes | yes | yes | **NO** | **NO** | yes | yes | yes | yes |
| `lstat` | yes | yes | yes | yes | **NO** | **NO** | yes | yes | yes | yes |
| `fstat` | yes | yes | yes | yes | **NO** | **NO** | yes | yes | yes | yes |
| `__xstat` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `__lxstat` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `__fxstat` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `stat64` | **NO** | **NO** | yes | yes | **NO** | **NO** | yes | yes | yes | yes |
| `opendir` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `readdir` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `readdir64` | **NO** | **NO** | yes | yes | yes | yes | yes | yes | yes | yes |
| `closedir` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `rewinddir` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `uname` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `glob` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `globfree` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `fnmatch` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `mkstemp` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `tmpfile` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `realpath` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `utimes` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `flock` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `fsync` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `fdatasync` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `truncate` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `ftruncate` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `fileno` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `umask` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `chdir` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `getcwd` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `access` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `symlink` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `readlink` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `link` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `chown` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `memset` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `memcpy` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `strcpy` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `strcat` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `calloc` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `malloc` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |
| `free` | yes | yes | yes | yes | yes | yes | yes | yes | yes | yes |

## Constants (compiled and run in-container)

| constant | alpine:3.20 amd64 | alpine:3.20 arm64 | debian:12 amd64 | debian:12 arm64 | ubuntu:20.04 amd64 | ubuntu:20.04 arm64 | ubuntu:22.04 amd64 | ubuntu:22.04 arm64 | ubuntu:24.04 amd64 | ubuntu:24.04 arm64 |
|---|---|---|---|---|---|---|---|---|---|---|
| `FNM_NOESCAPE` | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 |
| `FNM_PATHNAME` | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 |
| `FNM_PERIOD` | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 |
| `FNM_CASEFOLD` | 16 | 16 | 16 | 16 | 16 | 16 | 16 | 16 | 16 | 16 |
| `FNM_LEADING_DIR` | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 |
| `LOCK_SH` | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 |
| `LOCK_EX` | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 |
| `LOCK_UN` | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 |
| `LOCK_NB` | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 |
| `GLOB_ERR` | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 |
| `GLOB_MARK` | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 |
| `GLOB_NOSORT` | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 |
| `GLOB_NOCHECK` | 16 | 16 | 16 | 16 | 16 | 16 | 16 | 16 | 16 | 16 |
| `GLOB_NOESCAPE` | 64 | 64 | 64 | 64 | 64 | 64 | 64 | 64 | 64 | 64 |
| `GLOB_BRACE` | NOT DEFINED | NOT DEFINED | 1024 | 1024 | 1024 | 1024 | 1024 | 1024 | 1024 | 1024 |
| `GLOB_ONLYDIR` | NOT DEFINED | NOT DEFINED | 8192 | 8192 | 8192 | 8192 | 8192 | 8192 | 8192 | 8192 |
| `SEEK_SET` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| `SEEK_CUR` | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 |
| `SEEK_END` | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 | 2 |

## struct layout (compiled and run in-container)

| fact | alpine:3.20 amd64 | alpine:3.20 arm64 | debian:12 amd64 | debian:12 arm64 | ubuntu:20.04 amd64 | ubuntu:20.04 arm64 | ubuntu:22.04 amd64 | ubuntu:22.04 arm64 | ubuntu:24.04 amd64 | ubuntu:24.04 arm64 |
|---|---|---|---|---|---|---|---|---|---|---|
| sizeof(struct stat) | 144 | 128 | 144 | 128 | 144 | 128 | 144 | 128 | 144 | 128 |
| offsetof st_mode | 24 | 16 | 24 | 16 | 24 | 16 | 24 | 16 | 24 | 16 |
| &nbsp;&nbsp;width st_mode | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 | 4 |
| offsetof st_nlink | 16 | 20 | 16 | 20 | 16 | 20 | 16 | 20 | 16 | 20 |
| &nbsp;&nbsp;width st_nlink | 8 | 4 | 8 | 4 | 8 | 4 | 8 | 4 | 8 | 4 |
| offsetof st_ino | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 |
| offsetof st_uid | 28 | 24 | 28 | 24 | 28 | 24 | 28 | 24 | 28 | 24 |
| offsetof st_gid | 32 | 28 | 32 | 28 | 32 | 28 | 32 | 28 | 32 | 28 |
| offsetof st_size | 48 | 48 | 48 | 48 | 48 | 48 | 48 | 48 | 48 | 48 |
| offsetof st_atime | 72 | 72 | 72 | 72 | 72 | 72 | 72 | 72 | 72 | 72 |
| offsetof st_mtime | 88 | 88 | 88 | 88 | 88 | 88 | 88 | 88 | 88 | 88 |
| offsetof st_ctime | 104 | 104 | 104 | 104 | 104 | 104 | 104 | 104 | 104 | 104 |
| offsetof st_dev | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| &nbsp;&nbsp;width st_dev | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 |
| offsetof st_rdev | 40 | 32 | 40 | 32 | 40 | 32 | 40 | 32 | 40 | 32 |
| &nbsp;&nbsp;width st_rdev | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 |
| offsetof st_blksize | 56 | 56 | 56 | 56 | 56 | 56 | 56 | 56 | 56 | 56 |
| &nbsp;&nbsp;width st_blksize | 8 | 4 | 8 | 4 | 8 | 4 | 8 | 4 | 8 | 4 |
| offsetof st_blocks | 64 | 64 | 64 | 64 | 64 | 64 | 64 | 64 | 64 | 64 |
| sizeof(struct dirent) | 280 | 280 | 280 | 280 | 280 | 280 | 280 | 280 | 280 | 280 |
| offsetof dirent.d_name | 19 | 19 | 19 | 19 | 19 | 19 | 19 | 19 | 19 | 19 |
| sizeof(glob_t) | 72 | 72 | 72 | 72 | 72 | 72 | 72 | 72 | 72 | 72 |
| offsetof glob_t.gl_pathc | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| offsetof glob_t.gl_pathv | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 |


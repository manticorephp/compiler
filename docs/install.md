# Installing Manticore — host dependencies and platform support

Manticore compiles PHP to a native binary. The *output* binaries are fully
static and have no runtime dependencies — they call libc and nothing else. The
*compiler*, however, shells out to a real toolchain and links against a few
system libraries, so the host needs those present at build time.

This is an end-user guide. Quick version lives in the README's `Requirements`.

---

## What the host needs, and why

| Dependency | Needed for | Where it is used |
|---|---|---|
| **`clang`**, LLVM **≥ 15** | assembling emitted LLVM IR → object | `src/Manticore/Main.php:866` |
| **`cc`** | linking objects → executable | `src/Manticore/Main.php:925` |
| **PHP 8.5** (Zend) | *cold bootstrap only* — seeds the first native compiler | `bin/compile:42` |
| **libpcre2** (8-bit) + `pcre2-config` | `preg_*` | `Main.php:287`, `src/Runtime/Pcre.php` |
| **OpenSSL 3** (libssl + libcrypto) + `pkg-config` | TLS streams, `hash`/`hmac` | `Main.php:307`, `src/Runtime/Openssl.php`, `src/Runtime/Crypto.php` |
| `bash`, `find`, `sort`, `xargs`, `sed`, `awk`, `grep`, `mktemp` | build scripts | `bin/compile`, `tools/*.sh` |

`pcre2-config --libs8` and `pkg-config --libs openssl` are how the link flags
are discovered; if either tool is missing, Manticore falls back to literal
`-lpcre2-8` and `-lssl -lcrypto`, which works only if the libraries sit on the
default search path.

PHP itself is needed **once**. `bin/compile` runs the compiler's own source
under Zend to produce a throwaway seed binary; that seed then builds the real
`bin/manticore`, and from then on the compiler rebuilds itself (`bin/build`).
Nothing the compiler emits ever calls into a PHP runtime.

### Hard floors — these are not "prefer newer"

- **LLVM ≥ 15.** Manticore emits opaque-pointer IR. clang 14 and older reject
  it outright: `ptr type is only supported in -opaque-pointers mode`. Debian
  bookworm's stock clang is 14, so it cannot build Manticore.
- **PHP 8.5 for the seed.** 8.5 is Manticore's *target* language version. An
  older Zend seed disagrees with the source it is compiling.
- **glibc ≥ 2.33.** Manticore binds plain `stat` / `lstat` / `fstat` by name.
  glibc only began exporting those in 2.33 — before that it exported just
  `__xstat` / `__lxstat` / `__fxstat`. Ubuntu 20.04 (glibc 2.31) therefore
  cannot link. Ubuntu 22.04, Debian 12 and Alpine can.

---

## Per-OS setup

### macOS (arm64 / x86_64) — the supported platform

```bash
xcode-select --install                  # clang + cc + ld
brew install php pcre2 openssl@3 pkg-config
```

Homebrew's `php` tracks the current release; check `php -v` reports 8.5.

### Debian / Ubuntu

Stock packages are not sufficient (clang 14, php 8.2), so PHP comes from
sury.org and clang from apt.llvm.org:

```bash
sudo apt-get install -y \
    gcc libc6-dev binutils make \
    libpcre2-dev libssl-dev pkg-config

# clang / LLVM (pick the newest that publishes packages for your release)
curl -sSL https://apt.llvm.org/llvm.sh | sudo bash -s 21
sudo ln -sf /usr/bin/clang-21 /usr/local/bin/clang
sudo ln -sf /usr/bin/clang-21 /usr/local/bin/cc

# PHP 8.5 (sury.org)
sudo apt-get install -y php8.5-cli php8.5-mbstring
sudo update-alternatives --set php /usr/bin/php8.5
```

The root `Dockerfile` does exactly this and is the authoritative version of
these steps.

### Alpine (musl)

```bash
apk add clang lld musl-dev pcre2-dev openssl-dev pkgconf bash php85-cli
```

musl exports plain `stat`/`lstat`/`fstat` and has `glob`/`globfree`, but lacks
`GLOB_BRACE`, `GLOB_ONLYDIR` and the LFS64 aliases (`stat64`) — a few filesystem
functions degrade accordingly.

---

## Platform support

| Platform | Status |
|---|---|
| macOS arm64 | **supported** — the development and gate platform |
| macOS x86_64 | **supported** |
| Linux glibc ≥ 2.33 (arm64 / x86_64) | **toolchain works, full build blocked** — see below |
| Linux glibc < 2.33 (e.g. Ubuntu 20.04) | **unsupported** — cannot link `stat` |
| Linux musl / Alpine | same as glibc, minus some `glob` constants |

### The Linux blocker

The seed now builds and links on Linux — the Apple-`ld`-only symbol scraper that
broke it was [issue #1], fixed. One blocker remains: `bin/compile` stage [5/5]
segfaults in `EmitLlvm::unboxCellToType` while building the standard library.
This is a live compiler bug, not a packaging problem — the Linux seed compiles
and runs a hello-world correctly. Detail, backtrace and scoping:
[`tools/docker/README.md`](../tools/docker/README.md).

Until it is fixed, use Manticore on macOS, or use the container toolchain below
for everything short of a full Linux build.

[issue #1]: https://github.com/manticorephp/compiler/issues/1

---

## Docker

The root `Dockerfile` has two targets.

**`toolchain`** — a ready host environment, nothing baked in. Mount a checkout
and work in it:

```bash
docker build --target toolchain -t manticore-toolchain .
docker run --rm -it -v "$PWD":/build/manticore -w /build/manticore \
    manticore-toolchain bash
```

**`build`** — copies the repo in and runs `bin/compile`. **This target does not
succeed yet**, for the Linux blocker above; it is wired up and correct, and will
go green when that bug is fixed.

```bash
docker build --target build -t manticore .
```

To run the libc probes and the AOT suite in a container, see
[`tools/docker/README.md`](../tools/docker/README.md).

---

## Troubleshooting

**`ptr type is only supported in -opaque-pointers mode`** during the seed
assemble step — clang is older than 15. Install a newer LLVM and make sure bare
`clang` on `PATH` resolves to it (apt.llvm.org installs `clang-21`, not `clang`).

**`undefined reference to 'pcre2_compile_8'`** (and six siblings) at the seed
link — this was [issue #1], the macOS-only symbol scraper. If you see it on a
current checkout, `tools/link_stubs.sh` failed to recognise your linker's
diagnostic format; it prints the raw linker output when that happens, so file
that output as a bug.

**`undefined reference to '__xstat'` / missing `stat`** at link — glibc older
than 2.33. See the hard floors above.

**`pcre2-config: command not found`** or link errors mentioning `-lpcre2-8` —
install the PCRE2 *development* package (`libpcre2-dev`, `pcre2-dev`, or
`brew install pcre2`), not just the runtime library. Same shape for OpenSSL.

**Seed build runs out of memory** — `bin/compile` invokes Zend with
`-d memory_limit=2048M`. A container with a lower hard limit will be OOM-killed.

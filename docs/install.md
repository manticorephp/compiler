# Installing Manticore — host dependencies and platform support

Manticore compiles PHP to a native binary with no PHP runtime in it — no
interpreter, no `php.ini`, no extension loader. It is not statically linked,
though: an output binary links libc, PCRE2 and OpenSSL dynamically, plus whatever
a program's FFI bindings name (libcurl, libsqlite3, …), so the machine that RUNS
it needs those libraries too. The *compiler* additionally shells out to a real
toolchain, so the host needs clang and `cc` present at build time.

This is an end-user guide. Quick version lives in the README's `Requirements`.

---

## Quick install

The installer takes a published build when one exists for your platform (linux
and macOS, arm64 and amd64), verified against the release's `SHA256SUMS`, and
otherwise builds **from source** — the compiler compiles itself. Either way it
needs the [host toolchain](#what-the-host-needs-and-why) present (it checks and
tells you what is missing), then puts everything under `$MANTICORE_HOME`
(default `~/.manticore`).

```bash
curl -fsSL https://raw.githubusercontent.com/manticorephp/compiler/main/install.sh | bash
# then, as the script prints:
export PATH="$HOME/.manticore/bin:$PATH"
manticore version        # -> manticore 0.11.0
```

Re-running the installer **upgrades in place**. When it builds from source, a
working `manticore` rebuilds the new version *with itself* (self-host, fast) and
the Zend seed is only the cold first boot. Knobs: `MANTICORE_HOME`,
`MANTICORE_VERSION` (a specific release), `MANTICORE_FROM_SOURCE=1` (skip the
download), `MANTICORE_REF` (branch/tag), `MANTICORE_REPO`, `MANTICORE_SRC`
(build a local checkout instead of cloning).

Published Linux builds are made on **Debian 12** (glibc 2.36), which is also what
development and CI use: a release has to run on the distribution someone already
has, and glibc is backwards compatible, not forwards.

### Via Composer

```bash
composer create-project manticorephp/compiler manticore   # builds into ~/.manticore
# or, if it is already a dependency:
vendor/bin/manticore-install
```

Composer here is a delivery + build trigger, not a runtime: the package ships
the PHP source and runs `install.sh`. (Composer only auto-runs scripts for the
*root* project, so a plain `composer require` of Manticore as a dependency needs
one manual `vendor/bin/manticore-install`.)

The installed layout is self-contained and needs no environment variables — the
binary finds its runtime relative to itself:

```
$MANTICORE_HOME/bin/manticore
$MANTICORE_HOME/lib/manticore_stdlib.o(.sig)
$MANTICORE_HOME/lib/prelude/*.php
```

---

## What the host needs, and why

| Dependency | Needed for | Where it is used |
|---|---|---|
| **`clang`**, LLVM **≥ 15** | assembling emitted LLVM IR → object | `Main.php` — `clang -c`, the compile and library paths |
| **`cc`** | linking objects → executable | `Main.php` — the `system("cc …")` link step |
| **PHP 8.5** (Zend) | *cold bootstrap only* — seeds the first native compiler | `bin/compile:41` |
| **libpcre2** (8-bit) + `pcre2-config` | `preg_*` | `Main.php::pcre2_link_flags()`, `src/Runtime/Pcre.php` |
| **OpenSSL 3** (libssl + libcrypto) + `pkg-config` | TLS streams, `hash`/`hmac` | `Main.php::openssl_link_flags()`, `src/Runtime/Openssl.php`, `src/Runtime/Crypto.php` |
| **libcurl ≥ 7.68** + `curl-config` — *only* to compile a program that calls `curl_*` | `ext/curl` | `Main.php::generic_link_flags()`, `prelude/curl.php` |
| **libsqlite3** + `pkg-config sqlite3` — *only* to compile a program that mentions `PDO` | `pdo_sqlite` | `Main.php::generic_link_flags()`, `prelude/pdo_sqlite.php` |
| **libxml2** (**dev** package) — *only* to compile a program that uses `DOM*` / `SimpleXML` | `ext/dom`, SimpleXML | `Main.php::generic_link_flags()`, `prelude/xml.php` |
| `bash`, `find`, `sort`, `xargs`, `sed`, `awk`, `grep`, `mktemp` | build scripts | `bin/compile`, `tools/*.sh` |

`pcre2-config --libs8` and `pkg-config --libs openssl` are how the link flags
are discovered; if either tool is missing, Manticore falls back to literal
`-lpcre2-8` and `-lssl -lcrypto`, which works only if the libraries sit on the
default search path.

libcurl is different from the other two: it is **demand-gated**. A program that
never calls a `curl_*` function emits no libcurl wrapper, so `-lcurl` never
reaches its link line and the library need not be installed at all. When it is
needed, discovery goes `pkg-config --libs curl` → `curl-config --libs` →
`-lcurl`; the first always fails (the pkg-config module is called `libcurl`), so
in practice `curl-config` is the one that answers — and it ships in the
**development** package, not with the `curl` command-line tool.

libsqlite3 is demand-gated the same way, and easier: a program that never
mentions `PDO` never links it, and `pkg-config --libs sqlite3` answers on the
first probe. It too lives in the **development** package, not in the `sqlite3`
command-line tool.

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
- **glibc ≥ 2.34**, measured rather than assumed: `readelf -V` on a released
  binary reports `GLIBC_2.34` as the highest symbol version it references. The
  floor starts with `stat` / `lstat` / `fstat`, which manticore binds by name and
  glibc only began exporting in 2.33 (before that it was `__xstat` / `__lxstat` /
  `__fxstat`), and 2.34 is where the rest of what it uses settles. Ubuntu 20.04
  (2.31) and Debian 11 (2.31) therefore cannot run it; RHEL 9 (2.34) is exactly
  at the floor; Ubuntu 22.04 (2.35) and Debian 12 (2.36) have room.

  ⚠ **The floor is the symbol versions a binary REFERENCES, not the glibc of the
  machine that built it.** This is worth stating because we got it wrong in the
  other direction: a compiler built on trixie (glibc 2.41) was expected to be
  unusable on bookworm (2.36), and it ran there without complaint — it asks for
  nothing newer than 2.34. Building on the older base is still the right default,
  because it makes the floor a property of the build rather than of whichever
  symbols a release happened not to touch.

---

## Per-OS setup

### macOS (arm64 / x86_64)

```bash
xcode-select --install                  # clang + cc + ld
brew install php pcre2 openssl@3 pkg-config curl   # curl: only for ext/curl
```

Homebrew's `php` tracks the current release; check `php -v` reports 8.5.

### Debian / Ubuntu

The DEFAULT `clang` is too old on both suites (14 on bookworm), and the stock
`php` is 8.2 — but a versioned `clang-22` is in Debian's own archive, so only
php needs a third-party source:

```bash
sudo apt-get install -y \
    gcc libc6-dev binutils make \
    libpcre2-dev libssl-dev pkg-config \
    libcurl4-openssl-dev libsqlite3-dev libxml2-dev \
    netbase

# clang / LLVM: the newest versioned package the suite carries
sudo apt-get install -y clang-22 lld-22
sudo ln -sf /usr/bin/clang-22 /usr/local/bin/clang
sudo ln -sf /usr/bin/clang-22 /usr/local/bin/cc

# PHP 8.5 (sury.org)
sudo apt-get install -y php8.5-cli php8.5-mbstring
sudo update-alternatives --set php /usr/bin/php8.5
```

⚠ **`netbase` is not optional.** It installs `/etc/services` and `/etc/protocols`,
which a bare `debian:12` ships without — omit it and the network stdlib silently
degrades (`getservbyname("http")` returns `false`) instead of failing loudly.

The root `Dockerfile` covers the same ground without pinning a version: it takes
the highest `clang-NN` the suite offers and refuses below 15. Pin `clang-22`
above only if you want a specific toolchain.

⚠ **`libxml2-dev`, not just `libxml2`.** `prelude/xml.php` binds libxml2 by name,
so the link needs the `libxml2.so` symlink the *development* package carries —
the runtime `libxml2.so.2` that clang drags in is not enough. This one went
undeclared for a long time and the XML cases passed anyway, because the LLVM
installer used to pull `llvm-NN-dev`, which depends on `libxml2-dev`. Removing
that installer took the symlink with it and turned eight `dom_*`/`simplexml_*`
cases red on both arches — a dependency held up by an accident.

### Alpine (musl)

```bash
apk add clang lld gcc musl-dev binutils pcre2-dev openssl-dev curl-dev sqlite-dev \
        pkgconf bash file make \
        php85 php85-ctype php85-mbstring php85-tokenizer php85-openssl \
        php85-phar php85-session php85-posix php85-iconv php85-fileinfo \
        php85-curl php85-pdo_sqlite
```

Alpine splits php far finer than Debian does, and the split is not cosmetic: `php85`
alone has no **ctype**, and the Zend seed dies on the first line of the bootstrap with
`Call to undefined function ctype_digit()`. Everything after `php85-mbstring` above is
what Debian's `php8.5-cli` bundles and Alpine does not.

`Dockerfile.alpine` is this list, as the same four stages as the Debian image, and
`bash tools/docker/run_tests.sh --alpine` runs the usual gate against it (its own image
tag and its own compiler-cache volume — a glibc binary does not run in a musl
container). It is **prepared, not gated**: `gate.yml` carries it as an opt-in
`workflow_dispatch` input marked `continue-on-error`, because nothing had ever checked
the claim below until that button existed.

musl exports plain `stat`/`lstat`/`fstat` and has `glob`/`globfree`, but lacks
`GLOB_BRACE`, `GLOB_ONLYDIR` and the LFS64 aliases (`stat64`) — a few filesystem
functions degrade accordingly.

---

## Platform support

| Platform | Status |
|---|---|
| macOS arm64 | **supported** — the primary development and gate platform |
| macOS x86_64 | **supported** |
| Linux glibc ≥ 2.34 (arm64 / x86_64) | **supported** — full build + self-host fixpoint pass |
| Linux glibc < 2.34 (e.g. Ubuntu 20.04, Debian 11) | **unsupported** — cannot link `stat` |
| Linux musl / Alpine | **prepared, not gated** — builds, minus some `glob` constants; `Dockerfile.alpine` + `run_tests.sh --alpine`, and an opt-in `gate.yml` job |

Both macOS and Linux build the compiler from the cold Zend seed, self-host
(`bin/build` rebuilds the compiler byte-for-byte), and pass the full AOT suite
and the self-host fixpoint. To reproduce the Linux build + suite in a container,
see [`tools/docker/README.md`](../tools/docker/README.md).

[issue #1]: https://github.com/manticorephp/compiler/issues/1

---

## Docker

The published image carries the compiler **and** the toolchain it shells out to,
which is the one form of delivery that needs nothing installed on the far side:

```bash
docker run --rm -v "$PWD":/work -u "$(id -u):$(id -g)" \
    ghcr.io/manticorephp/compiler manticore compile app.php -o app
```

`-u "$(id -u):$(id -g)"` is not decoration: the image runs as uid 1000, and
without it the binary it writes into your bind mount comes back owned by someone
else.

The root `Dockerfile` builds that image, and three stages lead to it:

| Target | What it is |
|---|---|
| `base` | clang + the `-dev` libraries the compiler links against. **No php.** |
| `toolchain` | `base` + PHP 8.5 — the cold-seed interpreter and the difftest oracle |
| `build` | `toolchain` + the compiler, cold-seeded from the source tree |
| `runtime` | `base` + that compiler — what gets published |

```bash
# a ready host environment; mount a checkout and work in it
docker build --target toolchain -t manticore-toolchain .
docker run --rm -it -v "$PWD":/build/manticore -w /build/manticore \
    manticore-toolchain bash

# the published shape, built locally
docker build --target runtime -t manticore .
```

php lives in `toolchain` and not in `base` on purpose: the shipped compiler is a
native binary and never asks for an interpreter, so `runtime` branches off
`base` and carries none.

The base is `ARG DEBIAN_TAG=12` (bookworm, glibc 2.36) for development, CI and
releases alike. One base is not tidiness: glibc is backwards compatible and not
forwards, so a compiler published from a newer base cannot seed a build on an
older one — split bases split the seed chain. `Dockerfile.alpine` is the musl
counterpart, with the same four stages.

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
than 2.34. See the hard floors above.

**`pcre2-config: command not found`** or link errors mentioning `-lpcre2-8` —
install the PCRE2 *development* package (`libpcre2-dev`, `pcre2-dev`, or
`brew install pcre2`), not just the runtime library. Same shape for OpenSSL.

**Seed build runs out of memory** — two different ceilings, and the second one
is the one people hit. `bin/compile` invokes Zend with `-d memory_limit=2048M`,
so a container with a lower hard limit is OOM-killed during the bootstrap. Past
that, the seed builds the whole compiler as one LLVM module, and **that peaks at
just under 7 GiB** (measured: 6.83 GiB on glibc, 6.94 GiB on musl — the libc is
not the variable). A machine or a Docker VM with 8 GB is therefore right at the
edge: glibc squeaks under and musl does not. Give the VM 12 GB, or use a warm
`bin/build`, which does not pay this at all.

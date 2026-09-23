# Manticore in a container. Four stages, three of them worth building:
#
#   docker build --target toolchain -t manticore-toolchain .
#   docker build --target runtime   -t manticore .
#   docker build --target build     -t manticore-build .
#
# `base`      — clang + the -dev libraries the compiler links against. No php.
# `toolchain` — base + php 8.5: the seed interpreter and the difftest oracle.
#               Mount a checkout into it and build by hand; this is what
#               tools/docker/run_tests.sh and the workflows use.
# `build`     — toolchain + the compiler cold-seeded from this source tree.
# `runtime`   — base + that compiler. What gets published; no php in it.
#
# Carries PHP 8.5 and the latest stable clang ON BOARD, deliberately -- Debian's
# stock php and clang are both wrong for this compiler:
#   * PHP 8.5 is manticore's target language version, so the Zend seed must be
#     8.5 or the seed disagrees with what it is compiling.
#   * clang 14 predates LLVM 15's opaque pointers and REJECTS the IR manticore
#     emits ("ptr type is only supported in -opaque-pointers mode"). Verified,
#     not assumed -- bookworm's DEFAULT clang-14 failed the seed assemble step.
# So: php from sury.org, and clang-22 from Debian's own archive — the DEFAULT
# clang is 14 on bookworm, but a versioned clang-22 sits in both suites.

# ONE base everywhere — development, CI and the release. It is the OLDER of the
# two suites on purpose: glibc is backwards compatible and not forwards, so a
# binary linked against trixie's 2.41 refuses to start on bookworm's 2.36, and
# a compiler published from the newer base cannot seed a build on the older one.
# Splitting the bases split the seed chain with them: CI published a trixie
# compiler that a bookworm release could not use, which left the release with
# nothing to warm-start from. Same base, one chain, and the floor is the older
# glibc for free.
ARG DEBIAN_TAG=12
FROM debian:${DEBIAN_TAG} AS base

ENV DEBIAN_FRONTEND=noninteractive

# libpcre2-dev  -> preg_* (src/Runtime/Pcre.php binds pcre2-8 by name)
# libssl-dev    -> TLS + hash/hmac (src/Runtime/Openssl.php, src/Runtime/Crypto.php)
# pkg-config    -> how Main.php discovers the openssl link flags
# gcc/libc6-dev -> `cc` drives the final link
# netbase       -> /etc/services + /etc/protocols, the databases getservby*() /
#                  getprotoby*() read; a bare debian:12 ships without them, so the
#                  network stdlib would find nothing (getservbyname("http") → false).
# libsqlite3-dev   -> pdo_sqlite (prelude/pdo_sqlite.php binds `sqlite3` by name).
#                     Unlike curl this one DOES have a pkg-config module, so
#                     Main.php's first probe answers and no *-config shim is needed.
# libcurl4-openssl-dev -> ext/curl (prelude/curl.php binds `curl` by name). The
#                  DEV package, not the `curl` CLI above: it is what ships
#                  `curl-config` and the `libcurl.so` symlink, and Main.php's
#                  generic_link_flags() needs one of them — `pkg-config --libs
#                  curl` fails everywhere, since the module is called libcurl.
# libxml2-dev  -> ext/dom + SimpleXML (prelude/xml.php binds `xml2` by name).
#                 It was NEVER declared here and the XML cases passed anyway,
#                 because llvm.sh installed llvm-NN-dev, which Depends: libxml2-dev
#                 — so the `libxml2.so` symlink `-lxml2` needs arrived as a side
#                 effect of a THIRD-PARTY installer. Dropping that installer took
#                 the symlink with it and turned 8 dom_/simplexml_ cases red on
#                 both arches. `libxml2.so.2` alone is not enough: clang pulls the
#                 runtime library, and the link wants the development one.
# curl + gnupg fetch and verify sury's signing key for php; nothing here is for
# clang any more — that comes from the distribution's own archive below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg \
        gcc libc6-dev libpcre2-dev libssl-dev libcurl4-openssl-dev libsqlite3-dev libxml2-dev pkg-config \
        binutils bash file make \
        netbase \
    && rm -rf /var/lib/apt/lists/*

# ---- clang/LLVM, from Debian itself ----
# NOT apt.llvm.org's llvm.sh any more. That script was here because bookworm's
# DEFAULT clang is 14, which predates LLVM 15's opaque pointers and rejects the
# IR manticore emits ("ptr type is only supported in -opaque-pointers mode").
# But the default is not the whole archive: both bases carry a versioned
# clang-22 in their own repositories — 1:22.1.8-1~deb13u4 on trixie and
# 1:22.1.8-1~deb12u1 on bookworm — so the third-party script was fetching what
# apt already had. It cost a network dependency on every image build, two
# packages that exist on one base and not the other (wget for the script,
# software-properties-common for the branch it takes on bookworm), and a failure
# mode with no diagnosis: it went down on amd64 in CI while arm64 passed.
#
# Take the highest clang-NN the distribution offers, and refuse loudly below 15
# rather than discovering it as a wall of IR errors during the seed.
RUN apt-get update \
    && CLANG_PKG="$(apt-cache pkgnames clang- | grep -E '^clang-[0-9]+$' | sort -V | tail -1)" \
    && test -n "$CLANG_PKG" || { echo "no versioned clang package in this suite" >&2; exit 1; } \
    && CLANG_VER="${CLANG_PKG#clang-}" \
    && [ "$CLANG_VER" -ge 15 ] \
        || { echo "$CLANG_PKG is too old: manticore emits opaque-pointer IR, LLVM >= 15" >&2; exit 1; } \
    && echo "using $CLANG_PKG from $(. /etc/os-release; echo "$VERSION_CODENAME")" \
    && apt-get install -y --no-install-recommends "$CLANG_PKG" "lld-$CLANG_VER" \
    && rm -rf /var/lib/apt/lists/* \
    && ln -sf "/usr/bin/$CLANG_PKG" /usr/local/bin/clang \
    && ln -sf "/usr/bin/$CLANG_PKG" /usr/local/bin/cc

RUN clang --version | head -1 && cc --version | head -1 \
    && pcre2-config --libs8 && pkg-config --libs openssl \
    && curl-config --libs && pkg-config --libs sqlite3

# Run as a normal, unprivileged user. Under root every file is writable/executable
# regardless of mode, so a suite that checks permissions diverges from a real
# deployment: is_writable() of a chmod(0400) file returns true as root (the kernel
# skips the DAC check for uid 0) but false for a normal user — matching macOS and
# the recorded expectations. `/build` is the scratch dir run_tests.sh copies into.
RUN useradd --create-home --uid 1000 --shell /bin/bash manticore \
    && mkdir -p /build \
    && chown -R manticore:manticore /build

WORKDIR /build
USER manticore
CMD ["/bin/bash"]


# ---- + PHP 8.5, the seed interpreter and the difftest oracle ----
#
# A STAGE of its own, because the shipped compiler does not need it: php is what
# cold-seeds the build and what difftest grades against, and neither happens in
# the image a user runs. `runtime` therefore branches off `base`, not off this.
#
# The `php8.5-*` extension packages are here for the ORACLE, not for linking:
# difftest grades our output against this php, so a case that calls curl_* or
# PDO can only be graded where the interpreter has that extension too. They are
# a different axis from the `lib*-dev` packages in `base`, which are what our own
# FFI bindings link against — `libsqlite3-dev` without `php8.5-sqlite3` links
# fine and leaves the oracle unable to run a single pdo_* case.
FROM base AS toolchain

USER root
RUN curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(. /etc/os-release; echo "$VERSION_CODENAME") main" \
        > /etc/apt/sources.list.d/php.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.5-cli php8.5-mbstring php8.5-curl php8.5-sqlite3 \
    && rm -rf /var/lib/apt/lists/* \
    && update-alternatives --set php /usr/bin/php8.5

RUN php --version \
    && php -r 'exit(function_exists("curl_init") ? 0 : 1);' \
    && php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'

USER manticore


# ---- build the compiler from source ----
#
# Bakes the compiler in: `bin/compile` cold-seeds src/ -> bin/manticore + lib/.
# The Linux blockers are fixed (issue #1 linker scrape; the [5/5] failures were
# FFI-wrapper linkage, a missing -lm, and an uninitialised exception runtime).
FROM toolchain AS build

# --chown so the unprivileged `manticore` user (set in toolchain) owns the tree
# and bin/compile can write bin/manticore + lib/ into it.
COPY --chown=manticore:manticore . /build/manticore
WORKDIR /build/manticore

# A stale macOS bin/manticore or lib/*.o would fake a pass, or link Mach-O into
# an ELF build. .dockerignore keeps them out of the context; belt and braces.
RUN rm -rf bin/manticore lib tests/aot/tmp \
    && bin/compile

ENV PATH="/build/manticore/bin:${PATH}"
CMD ["/bin/bash"]


# ---- what a USER runs: the compiler, and the toolchain it shells out to ----
#
#   docker run --rm -v "$PWD":/work -u "$(id -u):$(id -g)" \
#       manticorephp/manticore manticore compile app.php -o app
#
# Off `base`, so no php and no oracle extensions ride along: the compiler is a
# native binary and never asks for an interpreter. clang, cc, pkg-config and the
# -dev libraries DO stay — the compiler shells out to clang to assemble its IR
# and to cc to link, so an image without them could not compile anything. That
# is also the honest answer to "why not ship a tarball instead": the tarball is
# these three directories, and the toolchain around them is what the image adds.
#
# `-u $(id -u)` above is not decoration: the image runs as uid 1000, and without
# it a binary compiled into a bind mount comes back owned by the wrong user.
FROM base AS runtime

COPY --from=build /build/manticore/bin/manticore /opt/manticore/bin/manticore
COPY --from=build /build/manticore/lib /opt/manticore/lib

ENV PATH="/opt/manticore/bin:${PATH}"
WORKDIR /work

# Reached by the bare name through $PATH — the shape that used to lose the
# prelude and the stdlib before self_dir() resolved the real executable.
RUN manticore version

USER manticore
CMD ["manticore", "--help"]

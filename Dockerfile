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
#     not assumed -- bookworm's stock clang-14 failed the seed assemble step.
# So: php from sury.org, clang from apt.llvm.org.

ARG DEBIAN_TAG=13
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
# wget + gnupg + lsb-release are llvm.sh's own dependencies, and `wget` is not a
# stand-in for the `curl` next to it: llvm.sh calls wget by name. What is NOT
# here is `software-properties-common` — trixie dropped the package, and
# llvm.sh stopped needing it in the same breath: on a new Debian it writes the
# deb822 source file itself instead of calling add-apt-repository.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl wget gnupg lsb-release \
        gcc libc6-dev libpcre2-dev libssl-dev libcurl4-openssl-dev libsqlite3-dev pkg-config \
        binutils bash file make \
        netbase \
    && rm -rf /var/lib/apt/lists/*

# ---- latest stable clang/LLVM (apt.llvm.org) ----
# NOT `llvm.sh` with no argument: that targets the development version (23 at time of
# writing), which publishes no packages for this suite and hard-fails the build. Walk
# candidate versions newest-first and keep the first that actually installs, so
# this tracks "latest that exists" without pinning to a version that will rot.
ARG LLVM_VERSIONS="22 21 20"
RUN curl -sSL https://apt.llvm.org/llvm.sh -o /tmp/llvm.sh \
    && chmod +x /tmp/llvm.sh \
    && installed="" \
    && for v in $LLVM_VERSIONS; do \
           echo "--- trying LLVM $v"; \
           if /tmp/llvm.sh "$v"; then installed="$v"; break; fi; \
       done \
    && test -n "$installed" || { echo "no LLVM version installable"; exit 1; } \
    && echo "installed LLVM $installed" \
    && rm -rf /var/lib/apt/lists/* /tmp/llvm.sh

# apt.llvm.org installs versioned binaries (clang-21); manticore invokes bare
# `clang`. Point `clang` and `cc` at the newest installed version.
RUN CLANG_BIN="$(ls -1 /usr/bin/clang-[0-9]* | grep -E 'clang-[0-9]+$' | sort -V | tail -1)" \
    && echo "using $CLANG_BIN" \
    && ln -sf "$CLANG_BIN" /usr/local/bin/clang \
    && ln -sf "$CLANG_BIN" /usr/local/bin/cc

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

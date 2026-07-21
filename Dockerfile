# Manticore in a container. Two targets:
#
#   docker build --target toolchain -t manticore-toolchain .
#   docker build --target build     -t manticore .          # see the WARNING below
#
# `toolchain` is a ready host environment (php 8.5 + clang + pcre2 + openssl);
# mount a checkout into it and build by hand. `build` bakes the compiler in.
# tools/docker/run_tests.sh builds `toolchain` too — one image definition, two
# consumers.
#
# Carries PHP 8.5 and the latest stable clang ON BOARD, deliberately -- Debian
# bookworm's stock php (8.2) and clang (14) are both wrong for this compiler:
#   * PHP 8.5 is manticore's target language version, so the Zend seed must be
#     8.5 or the seed disagrees with what it is compiling.
#   * clang 14 predates LLVM 15's opaque pointers and REJECTS the IR manticore
#     emits ("ptr type is only supported in -opaque-pointers mode"). Verified,
#     not assumed -- stock bookworm clang-14 fails the seed assemble step.
# So: php from sury.org, clang from apt.llvm.org.

FROM debian:12 AS toolchain

ENV DEBIAN_FRONTEND=noninteractive

# libpcre2-dev  -> preg_* (src/Runtime/Pcre.php binds pcre2-8 by name)
# libssl-dev    -> TLS + hash/hmac (src/Runtime/Openssl.php, src/Runtime/Crypto.php)
# pkg-config    -> how Main.php discovers the openssl link flags
# gcc/libc6-dev -> `cc` drives the final link
# netbase       -> /etc/services + /etc/protocols, the databases getservby*() /
#                  getprotoby*() read; a bare debian:12 ships without them, so the
#                  network stdlib would find nothing (getservbyname("http") → false).
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg lsb-release software-properties-common \
        gcc libc6-dev libpcre2-dev libssl-dev pkg-config binutils bash file make \
        netbase \
    && rm -rf /var/lib/apt/lists/*

# ---- PHP 8.5 (sury.org) ----
RUN curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.5-cli php8.5-mbstring \
    && rm -rf /var/lib/apt/lists/* \
    && update-alternatives --set php /usr/bin/php8.5

# ---- latest stable clang/LLVM (apt.llvm.org) ----
# NOT `llvm.sh` with no argument: that targets the trunk version (22 at time of
# writing), which publishes no bookworm packages and hard-fails the build. Walk
# candidate versions newest-first and keep the first that actually installs, so
# this tracks "latest that exists" without pinning to a version that will rot.
ARG LLVM_VERSIONS="21 20 19"
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

RUN php --version && clang --version | head -1 && cc --version | head -1 \
    && pcre2-config --libs8 && pkg-config --libs openssl

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

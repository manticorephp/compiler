# Io\Poll Linux-parity oracle: php 8.6 (the only build with Io\Poll) + a C
# toolchain so Manticore can self-build and compile the cases in one image.
# Debian-based php:8.6-rc image; add clang/gcc for `clang -c` + `cc` link.
FROM php:8.6-rc-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        clang gcc libc6-dev pkg-config ca-certificates gdb valgrind \
    && rm -rf /var/lib/apt/lists/* \
    && (command -v cc >/dev/null || ln -s "$(command -v gcc)" /usr/local/bin/cc)

WORKDIR /build

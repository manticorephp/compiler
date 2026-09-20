#!/usr/bin/env bash
# Build manticore from source in a Linux container and run the WHOLE
# AOT suite there. Both arches; arm64 is native on an Apple Silicon host,
# amd64 is TRANSLATED — Docker Desktop uses Rosetta, not qemu, and that is not a
# pedantic difference: Rosetta reserves address space of its own, so a deliberately
# impossible mmap kills the translator ("rosetta error: could not find free space")
# instead of returning MAP_FAILED the way a real x86 kernel would.
#
#   bash tools/docker/run_tests.sh                 # arm64
#   bash tools/docker/run_tests.sh --amd64         # amd64 (emulated)
#   bash tools/docker/run_tests.sh --both
#   bash tools/docker/run_tests.sh --shell         # drop into the container
#   bash tools/docker/run_tests.sh --cold          # ignore the self-host cache
#
# The repo is mounted READ-ONLY and copied to a scratch dir inside the
# container: a self-hosted build writes bin/manticore + lib/, and the host
# checkout is macOS -- a rw mount would overwrite host binaries with Linux ones.
# The steps themselves live in tools/docker/gate.sh — ONE definition, shared with
# .github/workflows/*.yml, so a CI green and a local green mean the same thing.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"

PLATFORMS=(linux/arm64)
SHELL_MODE=0
GATE_MODE=0
COLD_MODE=0
for arg in "$@"; do
    case "$arg" in
        --amd64) PLATFORMS=(linux/amd64) ;;
        --both)  PLATFORMS=(linux/arm64 linux/amd64) ;;
        --shell) SHELL_MODE=1 ;;
        --cold)  COLD_MODE=1 ;;
        # The HEAVY gate, on Linux: self-host build (or cold fallback) + full suite
        # + difftest (php is in the image) + selfhost_fixpoint (fixpoint, MIR golden,
        # rebuild-stability). macOS green proves nothing about the epoll path, the
        # Linux socket/errno constants or a glibc free(), so this is the only honest
        # gate for anything touching them.
        --gate)  GATE_MODE=1 ;;
        *) echo "usage: $0 [--amd64|--both|--shell|--gate|--cold]" >&2; exit 2 ;;
    esac
done


IMAGE_BASE=manticore-toolchain

for platform in "${PLATFORMS[@]}"; do
    arch="${platform#linux/}"
    image="$IMAGE_BASE:$arch"
    cache_volume="manticore-compiler-cache-$arch"
    echo "############ $platform ############" >&2
    # The root Dockerfile's `toolchain` target — the same image an end user
    # builds. Its `build` target is deliberately NOT used here: this harness runs
    # bin/compile against a bind-mounted working tree, not a baked-in copy.
    docker build --platform "$platform" --target toolchain -t "$image" \
        -f "$ROOT/Dockerfile" "$ROOT" >&2

    # Keep Linux ELF artifacts outside the host checkout. A warmed compiler can
    # self-host the current source tree, so an edit need not pay the Zend cold
    # seed again. The gate validates the architecture/toolchain marker itself
    # and falls back to a cold seed if the cache cannot be used.
    docker volume create "$cache_volume" >/dev/null
    docker run --rm --platform "$platform" --user root \
        --mount "type=volume,src=$cache_volume,dst=/compiler-cache" \
        "$image" chown -R manticore:manticore /compiler-cache

    if [ "$SHELL_MODE" = "1" ]; then
        exec docker run --rm -it --platform "$platform" \
            -v "$ROOT":/repo:ro "$image" /bin/bash
    fi

    docker run --rm --platform "$platform" \
        -v "$ROOT":/repo:ro \
        --mount "type=volume,src=$cache_volume,dst=/compiler-cache" \
        -e MC_GATE="$GATE_MODE" \
        -e MC_COMMIT="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)" \
        -e MC_COLD="$COLD_MODE" \
        -e MC_COMPILER_CACHE=/compiler-cache \
        -e MC_STABILITY_N="${MC_STABILITY_N:-2}" \
        -e MC_JOBS="${MC_JOBS:-0}" \
        "$image" /bin/bash /repo/tools/docker/gate.sh \
        && echo "### $platform: PASS" >&2 \
        || echo "### $platform: FAIL (exit $?)" >&2
done

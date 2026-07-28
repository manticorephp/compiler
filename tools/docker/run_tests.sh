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
#
# The repo is mounted READ-ONLY and copied to a scratch dir inside the
# container: bin/compile writes bin/manticore + lib/, and the host checkout is
# macOS -- a rw mount would overwrite host binaries with Linux ones.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"

PLATFORMS=(linux/arm64)
SHELL_MODE=0
GATE_MODE=0
for arg in "$@"; do
    case "$arg" in
        --amd64) PLATFORMS=(linux/amd64) ;;
        --both)  PLATFORMS=(linux/arm64 linux/amd64) ;;
        --shell) SHELL_MODE=1 ;;
        # The HEAVY gate, on Linux: cold seed + full suite + difftest (php is in the
        # image) + selfhost_fixpoint (fixpoint, self-host suite, MIR golden,
        # rebuild-stability). macOS green proves nothing about the epoll path, the
        # Linux socket/errno constants or a glibc free(), so this is the only honest
        # gate for anything touching them.
        --gate)  GATE_MODE=1 ;;
        *) echo "usage: $0 [--amd64|--both|--shell|--gate]" >&2; exit 2 ;;
    esac
done

# The in-container build+test. Kept as a heredoc so the image needs no copy of
# it and it always matches this script.
read -r -d '' RUNNER <<'EOS' || true
set -uo pipefail
echo "=== host: $(uname -m) / $(. /etc/os-release; echo "$PRETTY_NAME")"
echo "=== php:  $(php -r 'echo PHP_VERSION;')"
echo "=== clang: $(clang --version | head -1)"

# Copy out of the read-only mount: the build writes into the tree.
cp -a /repo /build/src-tree
cd /build/src-tree
# A stale macOS bin/manticore + lib/*.o from the host tree would fake a pass
# (or link Mach-O into an ELF build). Start from a clean slate.
rm -rf bin/manticore lib/ tests/aot/tmp 2>/dev/null || true

echo
echo "=== bin/compile (cold Zend seed) ==="
# NEVER pipe this: `set -o pipefail` is not in play for the reader's eye, and a
# pipe would report tail's exit code instead of the build's. Redirect instead.
if bin/compile > /build/compile.log 2>&1; then
    echo "bin/compile: OK"
    tail -5 /build/compile.log
    COMPILE_OK=1
else
    rc=$?
    echo "bin/compile: FAILED (exit $rc)"
    echo "--- last 60 lines of the build log ---"
    tail -60 /build/compile.log
    COMPILE_OK=0
fi

if [ "$COMPILE_OK" != "1" ]; then
    echo
    echo "=== RESULT: build failed, suite not run ==="
    exit 1
fi

echo
echo "=== tests/aot/run.sh (full suite) ==="
bash tests/aot/run.sh > /build/suite.log 2>&1
suite_rc=$?
tail -15 /build/suite.log

if [ "${MC_GATE:-0}" != "1" ]; then
    echo
    echo "=== RESULT: suite exit=$suite_rc ==="
    exit $suite_rc
fi

echo
echo "=== tools/difftest.sh (php parity, Linux) ==="
bash tools/difftest.sh > /build/difftest.log 2>&1
diff_rc=$?
tail -8 /build/difftest.log

echo
echo "=== tools/selfhost_fixpoint.sh (fixpoint + MIR golden + stability) ==="
# Stability defaults to 5x2 rebuilds; a container is slower and each cold seed is
# minutes, so 2x2 is the useful default here (MC_STABILITY_N overrides).
MC_STABILITY_N="${MC_STABILITY_N:-2}" bash tools/selfhost_fixpoint.sh > /build/fixpoint.log 2>&1
fix_rc=$?
tail -12 /build/fixpoint.log

echo
echo "=== RESULT (Linux gate): suite=$suite_rc difftest=$diff_rc fixpoint=$fix_rc ==="
[ "$suite_rc" = "0" ] && [ "$diff_rc" = "0" ] && [ "$fix_rc" = "0" ] || exit 1
exit 0
EOS

IMAGE_BASE=manticore-toolchain

for platform in "${PLATFORMS[@]}"; do
    arch="${platform#linux/}"
    image="$IMAGE_BASE:$arch"
    echo "############ $platform ############" >&2
    # The root Dockerfile's `toolchain` target — the same image an end user
    # builds. Its `build` target is deliberately NOT used here: this harness runs
    # bin/compile against a bind-mounted working tree, not a baked-in copy.
    docker build --platform "$platform" --target toolchain -t "$image" \
        -f "$ROOT/Dockerfile" "$ROOT" >&2

    if [ "$SHELL_MODE" = "1" ]; then
        exec docker run --rm -it --platform "$platform" \
            -v "$ROOT":/repo:ro "$image" /bin/bash
    fi

    docker run --rm --platform "$platform" \
        -v "$ROOT":/repo:ro \
        -e MC_GATE="$GATE_MODE" \
        -e MC_STABILITY_N="${MC_STABILITY_N:-2}" \
        "$image" /bin/bash -c "$RUNNER" \
        && echo "### $platform: PASS" >&2 \
        || echo "### $platform: FAIL (exit $?)" >&2
done

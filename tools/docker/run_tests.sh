#!/usr/bin/env bash
# Tier 2: build manticore from source in a Linux container and run the WHOLE
# AOT suite there. Both arches; arm64 is native on an Apple Silicon host,
# amd64 runs under qemu (slow but it works).
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
case "${1:-}" in
    --amd64) PLATFORMS=(linux/amd64) ;;
    --both)  PLATFORMS=(linux/arm64 linux/amd64) ;;
    --shell) SHELL_MODE=1 ;;
    "")      ;;
    *) echo "usage: $0 [--amd64|--both|--shell]" >&2; exit 2 ;;
esac

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
echo
echo "=== RESULT: suite exit=$suite_rc ==="
exit $suite_rc
EOS

IMAGE_BASE=manticore-toolchain

for platform in "${PLATFORMS[@]}"; do
    arch="${platform#linux/}"
    image="$IMAGE_BASE:$arch"
    echo "############ $platform ############" >&2
    # The root Dockerfile's `toolchain` target — the same image an end user
    # builds. Its `build` target is deliberately NOT used here: this tier runs
    # bin/compile against a bind-mounted working tree, not a baked-in copy.
    docker build --platform "$platform" --target toolchain -t "$image" \
        -f "$ROOT/Dockerfile" "$ROOT" >&2

    if [ "$SHELL_MODE" = "1" ]; then
        exec docker run --rm -it --platform "$platform" \
            -v "$ROOT":/repo:ro "$image" /bin/bash
    fi

    docker run --rm --platform "$platform" \
        -v "$ROOT":/repo:ro \
        "$image" /bin/bash -c "$RUNNER" \
        && echo "### $platform: PASS" >&2 \
        || echo "### $platform: FAIL (exit $?)" >&2
done

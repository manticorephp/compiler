#!/usr/bin/env bash
#
# Linux live-set attribution — heaptrack inside the gate toolchain image.
#
#   bash tools/docker/prof/run.sh [--amd64]
#
# heaptrack gives directly what the macOS path reconstructs from snapshots: the
# PEAK live set with its allocating backtraces. Its output is folded by the same
# tools/prof/report.php, so a Linux table and a macOS table are comparable.
#
# Like the macOS path, the compiler is seeded with MANTICORE_POOL=0 — the pool
# carves objects out of one big mmap and any malloc-level profiler would see a
# single allocation instead of millions (Debug.php:124: the flag must hold for
# the WHOLE build; bin/compile passes its environment to both halves).
#
# `perf` is deliberately not here: it needs a kernel-matched package plus
# --cap-add SYS_ADMIN, which this harness does not grant.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
PLATFORM="linux/arm64"
for a in "$@"; do
    case "$a" in
        --amd64) PLATFORM="linux/amd64" ;;
        *) echo "unknown flag: $a" >&2; exit 2 ;;
    esac
done
ARCH="${PLATFORM#linux/}"

docker build --platform "$PLATFORM" --target toolchain \
    -t "manticore-toolchain:$ARCH" -f "$ROOT/Dockerfile" "$ROOT" >&2
docker build --platform "$PLATFORM" --build-arg "BASE=manticore-toolchain:$ARCH" \
    -t "manticore-prof:$ARCH" -f "$ROOT/tools/docker/prof/Dockerfile" "$ROOT/tools/docker/prof" >&2

RUNNER=$(cat <<'EOS'
set -e
cp -a /repo /build/src-tree
cd /build/src-tree
rm -rf bin/manticore lib tests/aot/tmp

echo "=== cold seed with MANTICORE_POOL=0 (malloc-visible allocations) ==="
MANTICORE_POOL=0 bin/compile

echo "=== heaptrack: bin/manticore build --apps-only ==="
export MANTICORE_PRELUDE=/build/src-tree/prelude
MANTICORE_STATS=1 heaptrack -o /build/heap \
    bin/manticore build --apps-only manticore.json 2>/build/stats.txt
ZIP="$(ls /build/heap.*.zst /build/heap.*.gz 2>/dev/null | head -1)"

echo "=== peak contributors ==="
heaptrack_print --print-peak 1 --print-flamegraph '' "$ZIP" > /build/peak.txt 2>/dev/null \
    || heaptrack_print "$ZIP" > /build/peak.txt
tail -5 /build/stats.txt
php tools/prof/report.php heaptrack /build/peak.txt 40
EOS
)

mkdir -p "$ROOT/tools/docker/prof/out"
docker run --rm --platform "$PLATFORM" \
    -v "$ROOT":/repo:ro \
    -v "$ROOT/tools/docker/prof/out":/out \
    "manticore-prof:$ARCH" /bin/bash -c "$RUNNER; cp /build/peak.txt /build/stats.txt /out/ 2>/dev/null || true"

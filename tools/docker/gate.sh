#!/usr/bin/env bash
#
# THE Linux gate, as run inside a container. One definition, two consumers:
# tools/docker/run_tests.sh (local, Docker Desktop) and .github/workflows/*.yml
# (scheduled). Never inline these steps into a workflow — a second copy is how a
# CI green and a local green stop meaning the same thing.
#
# Expects: the repo mounted READ-ONLY at /repo (or $MC_REPO) and a writable
# scratch dir (/build, or $MC_WORK). The tree is COPIED out of the mount because
# the build writes bin/manticore + lib/ into it, and the host checkout is
# usually macOS — a rw mount would drop Mach-O binaries into a Linux build or
# ELF ones onto the host.
#
# Env:
#   MC_GATE=0|1      0 (default) = cold seed + full AOT suite.
#                    1 = + difftest (php parity) + selfhost_fixpoint
#                        (fixpoint, MIR golden, rebuild stability).
#   MC_JOBS=<n>      forwarded to tests/aot/run.sh (0 = one case per core).
#                    Default 0 here: a gate machine is idle otherwise.
#   MC_STABILITY_N   rebuild-stability rounds (default 2 — a container cold seed
#                    is minutes, and the local default of 5 is a different budget).
#   MC_REPO          read-only source mount (default /repo)
#   MC_WORK          writable scratch (default /build)
#   MC_LOGDIR        where the stage logs land (default $MC_WORK)
#
# Exit 0 only if every stage it ran passed.
set -uo pipefail

MC_GATE="${MC_GATE:-0}"
MC_JOBS="${MC_JOBS:-0}"
MC_STABILITY_N="${MC_STABILITY_N:-2}"
MC_REPO="${MC_REPO:-/repo}"
MC_WORK="${MC_WORK:-/build}"
MC_LOGDIR="${MC_LOGDIR:-$MC_WORK}"

mkdir -p "$MC_WORK" "$MC_LOGDIR"

echo "=== host:  $(uname -m) / $(. /etc/os-release; echo "$PRETTY_NAME")"
echo "=== php:   $(php -r 'echo PHP_VERSION;')"
echo "=== clang: $(clang --version | head -1)"
echo "=== commit:$(git -C "$MC_REPO" rev-parse --short HEAD 2>/dev/null || echo ' unknown') $(git -C "$MC_REPO" rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
echo "=== gate:  MC_GATE=$MC_GATE MC_JOBS=$MC_JOBS MC_STABILITY_N=$MC_STABILITY_N opt=-O2 (default)"

TREE="$MC_WORK/src-tree"
rm -rf "$TREE"
cp -a "$MC_REPO" "$TREE"
cd "$TREE" || exit 1
# A stale macOS bin/manticore + lib/*.o from the host tree would fake a pass (or
# link Mach-O into an ELF build). Start from a clean slate.
rm -rf bin/manticore lib/ tests/aot/tmp 2>/dev/null || true

echo
echo "=== bin/compile (cold Zend seed) ==="
# NEVER pipe this: a pipe reports tail's exit code instead of the build's.
if bin/compile > "$MC_LOGDIR/compile.log" 2>&1; then
    echo "bin/compile: OK"
    tail -5 "$MC_LOGDIR/compile.log"
else
    rc=$?
    echo "bin/compile: FAILED (exit $rc)"
    echo "--- last 60 lines of the build log ---"
    tail -60 "$MC_LOGDIR/compile.log"
    echo
    echo "=== RESULT: build failed, suite not run ==="
    exit 1
fi

echo
echo "=== tests/aot/run.sh (full suite, -j $MC_JOBS) ==="
MC_JOBS="$MC_JOBS" bash tests/aot/run.sh > "$MC_LOGDIR/suite.log" 2>&1
suite_rc=$?
tail -15 "$MC_LOGDIR/suite.log"

if [ "$MC_GATE" != "1" ]; then
    echo
    echo "=== RESULT: suite=$suite_rc ==="
    exit $suite_rc
fi

echo
echo "=== tools/difftest.sh (php parity, Linux) ==="
bash tools/difftest.sh > "$MC_LOGDIR/difftest.log" 2>&1
diff_rc=$?
tail -8 "$MC_LOGDIR/difftest.log"

echo
echo "=== tools/selfhost_fixpoint.sh (fixpoint + MIR golden + stability) ==="
MC_STABILITY_N="$MC_STABILITY_N" bash tools/selfhost_fixpoint.sh > "$MC_LOGDIR/fixpoint.log" 2>&1
fix_rc=$?
tail -12 "$MC_LOGDIR/fixpoint.log"

echo
echo "=== RESULT (Linux gate): suite=$suite_rc difftest=$diff_rc fixpoint=$fix_rc ==="
[ "$suite_rc" = "0" ] && [ "$diff_rc" = "0" ] && [ "$fix_rc" = "0" ] || exit 1
exit 0

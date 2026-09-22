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
#   MC_GATE=0|1      0 (default) = self-hosted cache (or cold fallback) + full
#                    AOT suite (bin/build from the cache, cold seed otherwise).
#                    1 = + difftest (php parity) + selfhost_fixpoint
#                        (fixpoint, MIR golden, rebuild stability).
#                    It is a shorthand for MC_DIFFTEST=1 MC_FIXPOINT=1; either
#                    one can be asked for on its own instead. The fixpoint is
#                    hours and answers a question that only a bootstrap or an
#                    ABI change can re-open, so CI asks for difftest alone.
#   MC_DIFFTEST=0|1  run tools/difftest.sh (default: MC_GATE)
#   MC_FIXPOINT=0|1  run tools/selfhost_fixpoint.sh (default: MC_GATE)
#   MC_JOBS=<n>      forwarded to tests/aot/run.sh (0 = one case per core).
#                    Default 0 here: a gate machine is idle otherwise.
#   MC_FILTER=<sub>  narrow the suite step to matching case names (`-k`), for
#                    chasing ONE Linux-only failure. Never with MC_GATE=1.
#   MC_STABILITY_N   rebuild-stability rounds (default 2 — a container cold seed
#                    is minutes, and the local default of 5 is a different budget).
#   MC_REPO          read-only source mount (default /repo)
#   MC_WORK          writable scratch (default /build)
#   MC_LOGDIR        where the stage logs land (default $MC_WORK)
#   MC_COMPILER_CACHE writable directory holding a compatible self-hosted
#                    compiler (optional; unset means always cold-seed)
#   MC_COLD=0|1      ignore a compiler cache and force the Zend cold seed
#
# Exit 0 only if every stage it ran passed.
set -uo pipefail

MC_GATE="${MC_GATE:-0}"
MC_DIFFTEST="${MC_DIFFTEST:-$MC_GATE}"
MC_FIXPOINT="${MC_FIXPOINT:-$MC_GATE}"
MC_JOBS="${MC_JOBS:-0}"
MC_STABILITY_N="${MC_STABILITY_N:-2}"
MC_REPO="${MC_REPO:-/repo}"
MC_WORK="${MC_WORK:-/build}"
MC_LOGDIR="${MC_LOGDIR:-$MC_WORK}"
MC_COMPILER_CACHE="${MC_COMPILER_CACHE:-}"
MC_COLD="${MC_COLD:-0}"

mkdir -p "$MC_WORK" "$MC_LOGDIR"

# /etc/os-release is Linux-only, and this script now also runs bare on a macOS
# CI runner, where the same steps need the same definition.
if [ -r /etc/os-release ]; then
    MC_OS="$(. /etc/os-release; echo "$PRETTY_NAME")"
else
    MC_OS="$(sw_vers -productName 2>/dev/null) $(sw_vers -productVersion 2>/dev/null)"
fi
echo "=== host:  $(uname -m) / $MC_OS"
echo "=== php:   $(php -r 'echo PHP_VERSION;')"
echo "=== clang: $(clang --version | head -1)"
# MC_COMMIT is what CI passes in: the image carries no git, and a bind-mounted
# checkout is a different owner than the container user, which `git` refuses.
echo "=== commit:${MC_COMMIT:-$(git -C "$MC_REPO" rev-parse --short HEAD 2>/dev/null || echo unknown)}"
echo "=== gate:  difftest=$MC_DIFFTEST fixpoint=$MC_FIXPOINT MC_JOBS=$MC_JOBS MC_STABILITY_N=$MC_STABILITY_N opt=-O2 (default)"

TREE="$MC_WORK/src-tree"
rm -rf "$TREE"
cp -a "$MC_REPO" "$TREE"
cd "$TREE" || exit 1
# A stale macOS bin/manticore + lib/*.o from the host tree would fake a pass (or
# link Mach-O into an ELF build). Start from a clean slate.
rm -rf bin/manticore bin/.manticore.prev bin/manticore.fast lib/ tests/aot/tmp 2>/dev/null || true

cache_id() {
    printf 'arch=%s\nclang=%s\nphp=%s\n' \
        "$(uname -m)" "$(clang --version | head -1)" "$(php -r 'echo PHP_VERSION;')"
}

restore_compiler_cache() {
    [ "$MC_COLD" != "1" ] || return 1
    [ -n "$MC_COMPILER_CACHE" ] || return 1
    [ -x "$MC_COMPILER_CACHE/bin/manticore" ] || return 1
    [ -f "$MC_COMPILER_CACHE/lib/manticore_stdlib.o" ] || return 1
    [ -f "$MC_COMPILER_CACHE/id" ] || return 1
    cache_id | cmp -s - "$MC_COMPILER_CACHE/id" || return 1

    mkdir -p bin lib
    cp "$MC_COMPILER_CACHE/bin/manticore" bin/manticore
    cp -a "$MC_COMPILER_CACHE/lib/." lib/
    bin/manticore version >/dev/null 2>&1
}

save_compiler_cache() {
    [ -n "$MC_COMPILER_CACHE" ] || return 0
    [ -x bin/manticore ] || return 0
    [ -f lib/manticore_stdlib.o ] || return 0

    tmp="$MC_COMPILER_CACHE/.next.$$"
    rm -rf "$tmp"
    mkdir -p "$tmp/bin" "$tmp/lib"
    cp bin/manticore "$tmp/bin/manticore"
    cp -a lib/. "$tmp/lib/"
    cache_id > "$tmp/id"
    rm -rf "$MC_COMPILER_CACHE/bin" "$MC_COMPILER_CACHE/lib" "$MC_COMPILER_CACHE/id"
    mv "$tmp/bin" "$tmp/lib" "$tmp/id" "$MC_COMPILER_CACHE/"
    rmdir "$tmp"
}

echo
if restore_compiler_cache; then
    # bin/build, not a bare `manticore build`: it preflights src/ against the
    # cached (one generation behind) compiler, builds to a temp path, smoke
    # tests, swaps, and only THEN lets the NEW binary build lib/. A one-pass
    # build would leave the stdlib a generation behind and overwrite the running
    # executable. A bootstrap gap exits 1 here and falls through to the seed.
    echo "=== bin/build (self-hosted from cache) ==="
    if bin/build > "$MC_LOGDIR/compile.log" 2>&1; then
        echo "bin/build: OK"
        tail -5 "$MC_LOGDIR/compile.log"
    else
        rc=$?
        echo "bin/build: FAILED (exit $rc); falling back to cold seed"
        tail -20 "$MC_LOGDIR/compile.log"
        rm -rf bin/manticore bin/.manticore.prev lib/
    fi
fi

if [ ! -x bin/manticore ]; then
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
fi

save_compiler_cache

echo
if [ -n "${MC_FILTER:-}" ]; then
    echo "=== tests/aot/run.sh (-k $MC_FILTER, -j $MC_JOBS) — NOT the gate ==="
    MC_JOBS="$MC_JOBS" bash tests/aot/run.sh -k "$MC_FILTER" > "$MC_LOGDIR/suite.log" 2>&1
else
    echo "=== tests/aot/run.sh (full suite, -j $MC_JOBS) ==="
    MC_JOBS="$MC_JOBS" bash tests/aot/run.sh > "$MC_LOGDIR/suite.log" 2>&1
fi
suite_rc=$?
tail -15 "$MC_LOGDIR/suite.log"

if [ "$MC_DIFFTEST" != "1" ] && [ "$MC_FIXPOINT" != "1" ]; then
    echo
    echo "=== RESULT: suite=$suite_rc ==="
    exit $suite_rc
fi

diff_rc=0
if [ "$MC_DIFFTEST" = "1" ]; then
    echo
    echo "=== tools/difftest.sh (php parity) ==="
    bash tools/difftest.sh > "$MC_LOGDIR/difftest.log" 2>&1
    diff_rc=$?
    tail -8 "$MC_LOGDIR/difftest.log"
fi

# ⚠ This one REPLACES bin/manticore with a stage binary while it runs. Harmless
# here — the tree is a scratch copy — but never point it at a working checkout.
fix_rc=0
if [ "$MC_FIXPOINT" = "1" ]; then
    echo
    echo "=== tools/selfhost_fixpoint.sh (fixpoint + MIR golden + stability) ==="
    MC_STABILITY_N="$MC_STABILITY_N" bash tools/selfhost_fixpoint.sh > "$MC_LOGDIR/fixpoint.log" 2>&1
    fix_rc=$?
    tail -12 "$MC_LOGDIR/fixpoint.log"
fi

echo
echo "=== RESULT (gate): suite=$suite_rc difftest=$diff_rc fixpoint=$fix_rc ==="
[ "$suite_rc" = "0" ] && [ "$diff_rc" = "0" ] && [ "$fix_rc" = "0" ] || exit 1
exit 0

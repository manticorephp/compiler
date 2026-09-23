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
#   MC_SEED_DIR      a directory holding a PUBLISHED compiler (bin/ + lib/), used
#                    when the cache misses and before the Zend seed. Validated by
#                    running it, not by an id — it came from another machine.
#   MC_COMPILER_CACHE writable directory holding a compatible self-hosted
#                    compiler (optional; unset means always cold-seed)
#   MC_COLD=0|1      ignore a compiler cache and force the Zend cold seed
#   MC_SUITE=0|1     0 = build and install_smoke only, no AOT suite. What the
#                    release asks for: CI has already run the suite against that
#                    commit, and a second run is a slower release, not new news.
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
MC_SUITE="${MC_SUITE:-1}"

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

# libc belongs in here with the rest: a glibc compiler does not run under musl
# and the loader's refusal is not a diagnosis. `ldd --version` names the
# implementation on both (glibc prints its version, musl prints its own banner
# on stderr and exits 1, which is why the output is merged and the exit ignored).
cache_id() {
    local libc
    if command -v ldd > /dev/null 2>&1; then
        libc="$( (ldd --version 2>&1 || true) | head -1 )"
    else
        # macOS has no ldd, and this script runs bare on a macOS runner too.
        libc="$(uname -s)"
    fi
    printf 'arch=%s\nlibc=%s\nclang=%s\nphp=%s\n' \
        "$(uname -m)" "$libc" \
        "$(clang --version | head -1)" \
        "$(php -r 'echo PHP_VERSION;')"
}

# The id is a NOTE, not a gate. It used to be one, and that cost a cold seed for
# every difference it could name — including the run that ADDED `libc` to it,
# where a cached compiler was thrown away because the id it was stored with
# predated the field. The same reasoning that applies to a published seed applies
# here: what matters is not whether this compiler was built by the same toolchain
# but whether it RUNS here and can build the tree. It only ever acts as a builder
# — bin/build compiles the new compiler from source with the CURRENT clang — so a
# toolchain difference does not reach the output, while a libc it cannot run
# under shows up immediately as a failed `version`.
restore_compiler_cache() {
    [ "$MC_COLD" != "1" ] || return 1
    [ -n "$MC_COMPILER_CACHE" ] || return 1
    [ -x "$MC_COMPILER_CACHE/bin/manticore" ] || return 1
    [ -f "$MC_COMPILER_CACHE/lib/manticore_stdlib.o" ] || return 1

    if [ -f "$MC_COMPILER_CACHE/id" ] && ! cache_id | cmp -s - "$MC_COMPILER_CACHE/id"; then
        echo "cache: stored under a different toolchain — trying it anyway"
        diff <(cache_id) "$MC_COMPILER_CACHE/id" | sed 's/^/       /' | head -8
    fi

    mkdir -p bin lib
    cp "$MC_COMPILER_CACHE/bin/manticore" bin/manticore
    cp -a "$MC_COMPILER_CACHE/lib/." lib/
    chmod u+x bin/manticore
    if ! bin/manticore version >/dev/null 2>&1; then
        echo "cache: holds a compiler that does not run here — ignoring it"
        rm -rf bin/manticore lib
        return 1
    fi
    return 0
}

# The PUBLISHED compiler, as a second warm source between the cache and Zend.
#
# The cache is the fast path and a fragile one: its key carries the Dockerfile
# hash, GitHub evicts an entry after a week idle, and a branch cannot see another
# branch's. A published image does not evict, so `MC_SEED_DIR` — a directory the
# caller extracted one into — is what keeps the cold seed for the cases that
# genuinely need it: a bootstrap gap, a new platform, no network.
#
# Validated by BEHAVIOUR, not by an id file: it comes from another machine and
# possibly another image, so the question is not "was it built here" but "does it
# run here and can it build". If it cannot, bin/build fails and the seed follows.
restore_published_seed() {
    [ "$MC_COLD" != "1" ] || return 1
    [ -n "${MC_SEED_DIR:-}" ] || return 1
    [ -x "$MC_SEED_DIR/bin/manticore" ] || return 1
    [ -f "$MC_SEED_DIR/lib/manticore_stdlib.o" ] || return 1

    mkdir -p bin lib
    cp "$MC_SEED_DIR/bin/manticore" bin/manticore
    cp -a "$MC_SEED_DIR/lib/." lib/
    chmod u+x bin/manticore
    if ! bin/manticore version >/dev/null 2>&1; then
        echo "seed: $MC_SEED_DIR holds a compiler that does not run here — ignoring it"
        rm -rf bin/manticore lib
        return 1
    fi
    return 0
}

# SAYS whether it worked, and that is the point. The cache directory is a host
# mount shared by two different users: on CI the files restored by actions/cache
# belong to the runner, while this container is uid 1000 — and unlinking an entry
# needs write permission on its DIRECTORY, not on the file — so a restored tree
# is unremovable from in here unless the workflow chmods it recursively. When
# that step is missing the copy fails, every message is an `rm: Permission
# denied` nobody reads, the job still passes, and the cache silently never
# updates: every run goes back to a cold seed for ever. A cache that cannot be
# written is not fatal, but it must not be quiet.
save_compiler_cache() {
    [ -n "$MC_COMPILER_CACHE" ] || return 0
    [ -x bin/manticore ] || return 0
    [ -f lib/manticore_stdlib.o ] || return 0

    tmp="$MC_COMPILER_CACHE/.next.$$"
    if ! { rm -rf "$tmp" && mkdir -p "$tmp/bin" "$tmp/lib"; } 2>/dev/null; then
        echo "cache: $MC_COMPILER_CACHE is not writable by uid $(id -u) — NOT saved"
        return 0
    fi
    cp bin/manticore "$tmp/bin/manticore"
    cp -a lib/. "$tmp/lib/"
    cache_id > "$tmp/id"

    if rm -rf "$MC_COMPILER_CACHE/bin" "$MC_COMPILER_CACHE/lib" "$MC_COMPILER_CACHE/id" 2>/dev/null \
            && mv "$tmp/bin" "$tmp/lib" "$tmp/id" "$MC_COMPILER_CACHE/" 2>/dev/null; then
        rmdir "$tmp" 2>/dev/null
        echo "cache: saved ($(du -sh "$MC_COMPILER_CACHE" 2>/dev/null | cut -f1))"
    else
        rm -rf "$tmp" 2>/dev/null
        echo "cache: the existing entry belongs to another user and cannot be replaced" \
             "from uid $(id -u) — NOT saved, the next run will cold-seed again"
    fi
}

echo
# Three sources, in falling order of cheapness: the cache this runner wrote, the
# compiler the project publishes, and Zend. The third is a RECOVERY path, not a
# step — it is what crosses a bootstrap gap, reaches a platform nothing has been
# published for, and re-derives the compiler from source when the chain is in
# doubt, which is why the release builds that way on purpose.
WARM=""
if restore_compiler_cache; then
    WARM="cache"
elif restore_published_seed; then
    WARM="published seed"
fi

if [ -n "$WARM" ]; then
    # bin/build, not a bare `manticore build`: it preflights src/ against the
    # warm (one generation behind) compiler, builds to a temp path, smoke
    # tests, swaps, and only THEN lets the NEW binary build lib/. A one-pass
    # build would leave the stdlib a generation behind and overwrite the running
    # executable. A bootstrap gap exits 1 here and falls through to the seed.
    echo "=== bin/build (self-hosted from the $WARM) ==="
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

# Before the suite, because it is seconds and it covers what the suite cannot:
# the suite always calls `bin/manticore` by path, so it never notices a compiler
# that cannot find its own prelude when it is reached the way an installed one is.
echo
echo "=== tools/install_smoke.sh (an installed layout finds its own lib/) ==="
bash tools/install_smoke.sh > "$MC_LOGDIR/install_smoke.log" 2>&1
install_rc=$?
tail -5 "$MC_LOGDIR/install_smoke.log"

echo
suite_rc=0
if [ "$MC_SUITE" != "1" ]; then
    # The release asks for this: it builds a compiler from a commit CI has
    # already run the suite against, and running it a second time buys a slower
    # release and no new information. install_smoke above still runs, because it
    # asks about the artifact being shipped rather than about the tree.
    echo "=== suite skipped (MC_SUITE=0) — this build is not a verdict on the tree ==="
    SUITE_LABEL=skipped
elif [ -n "${MC_FILTER:-}" ]; then
    echo "=== tests/aot/run.sh (-k $MC_FILTER, -j $MC_JOBS) — NOT the gate ==="
    MC_JOBS="$MC_JOBS" bash tests/aot/run.sh -k "$MC_FILTER" > "$MC_LOGDIR/suite.log" 2>&1
    suite_rc=$?
    tail -15 "$MC_LOGDIR/suite.log"
else
    echo "=== tests/aot/run.sh (full suite, -j $MC_JOBS) ==="
    MC_JOBS="$MC_JOBS" bash tests/aot/run.sh > "$MC_LOGDIR/suite.log" 2>&1
    suite_rc=$?
    tail -15 "$MC_LOGDIR/suite.log"
fi

if [ "$MC_DIFFTEST" != "1" ] && [ "$MC_FIXPOINT" != "1" ]; then
    echo
    echo "=== RESULT: suite=${SUITE_LABEL:-$suite_rc} install_smoke=$install_rc ==="
    [ "$suite_rc" = "0" ] && [ "$install_rc" = "0" ] || exit 1
    exit 0
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
echo "=== RESULT (gate): suite=${SUITE_LABEL:-$suite_rc} install_smoke=$install_rc difftest=$diff_rc fixpoint=$fix_rc ==="
[ "$suite_rc" = "0" ] && [ "$install_rc" = "0" ] && [ "$diff_rc" = "0" ] && [ "$fix_rc" = "0" ] || exit 1
exit 0

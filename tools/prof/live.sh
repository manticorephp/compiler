#!/usr/bin/env bash
#
# LIVE-SET attribution for a compiler run — macOS MallocStackLogging +
# malloc_history, no compiler instrumentation.
#
#   bash tools/prof/live.sh --build                  # produce bin/manticore.nopool first
#   bash tools/prof/live.sh [-- <manticore args>]    # default: build --apps-only manticore.json
#
# WHY A SEPARATE BINARY. The small-object pool carves objects and arrays out of
# ONE 1 GiB mmap (EmitLlvmRuntime.php:63) — malloc-based tooling cannot see a
# single one of them. `MANTICORE_POOL=0` is read by the COMPILER at compile time
# (Debug::initFromEnvironment) and baked into the IR it emits, so it must be set
# while BUILDING the binary that will be profiled, and for the whole build (the
# allocator bodies are linkonce_odr — a half-and-half link keeps one body of
# each, silently).
#
# WHAT IS STILL INVISIBLE, and why it is not a lie:
#   - string free lists (@__mir_strpool0/1) have no off switch and never return
#     memory to libc. A block parked there is STILL RESIDENT, and charging it to
#     whoever allocated it is the honest answer for peak RSS.
#   - arena chunks charge to __mir_arena_alloc, not the requester. The arena was
#     measured as barely used by this compiler; if it lands top-5, rerun with
#     MANTICORE_ARENA_ARRAYS=0 and `--memory=rc` for a control.
#
# OUTDIR=<dir>    keep artifacts (default: temp dir)
# SNAPS=<n>       max malloc_history snapshots, default 3 (each takes minutes on
#                 a multi-GB process — this is the cost knob)
# RISE=<pct>      take a snapshot when RSS is this much above the last one, default 25

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
# shellcheck source=tools/prof/lib.sh
source "$ROOT/tools/prof/lib.sh"
mc_export_prelude "$ROOT"

command -v malloc_history >/dev/null 2>&1 || { echo "prof: no \`malloc_history\` — macOS only (Linux: tools/docker/prof)" >&2; exit 1; }

NOPOOL="$ROOT/bin/manticore.nopool"

if [[ "${1:-}" == "--build" ]]; then
    case "$ROOT" in
        */manticore) echo "prof: refusing to build a no-pool compiler in the MAIN checkout —" >&2
                     echo "      it would leave bin/manticore + lib/*.o non-shipping. Use a worktree." >&2
                     exit 1 ;;
    esac
    echo "── building a no-pool compiler (MANTICORE_POOL=0, both passes) ──"
    MANTICORE_POOL=0 bin/build
    cp bin/manticore "$NOPOOL"
    echo "ok: $NOPOOL (lib/*.o in this worktree are no-pool too — do not copy them out)"
    exit 0
fi

# BIN= measures any no-pool-compiled program (tools/prof/fixture.php is the
# harness's own self-test); the default is the compiler compiling itself.
BIN="${BIN:-$NOPOOL}"
[[ -x "$BIN" ]] || { echo "prof: no $BIN — run: bash tools/prof/live.sh --build" >&2; exit 1; }

ARGS=(build --apps-only manticore.json)
if [[ "$BIN" != "$NOPOOL" ]]; then ARGS=(); fi
if [[ "${1:-}" == "--" ]]; then shift; ARGS=("$@"); fi

OUTDIR="${OUTDIR:-$(mktemp -d)}"
mkdir -p "$OUTDIR/mslog"
SNAPS="${SNAPS:-3}"
RISE="${RISE:-25}"

echo "prof: $BIN ${ARGS[*]}"
echo "prof: artifacts in $OUTDIR"

# The stack log goes to a directory of its own so it does not grow the address
# space being measured any more than it must.
MallocStackLogging=1 \
MallocStackLoggingDirectory="$OUTDIR/mslog" \
MANTICORE_STATS=1 \
    "$BIN" "${ARGS[@]}" >"$OUTDIR/build.log" 2>"$OUTDIR/stats.txt" &
PID=$!

peak=0
taken=0
last_snap_rss=0
start=$SECONDS
: >"$OUTDIR/rss.tsv"

snapshot() {
    local rss_kb="$1" ms="$2" tag
    tag="$(printf '%06d' "$ms")"
    echo "prof: snapshot at ${ms}ms, rss $(( rss_kb / 1024 )) MB → snap-$tag" >&2
    heap "$PID" >"$OUTDIR/heap-$tag.txt" 2>/dev/null || true
    malloc_history "$PID" -allBySize >"$OUTDIR/snap-$tag.txt" 2>/dev/null || true
    echo "$ms $rss_kb" >>"$OUTDIR/snaps.tsv"
}

while kill -0 "$PID" 2>/dev/null; do
    # ps prints KiB. Match the pid exactly — matching by path picks up this
    # script's own command line (the trap tools/fiber_ceiling.sh documents).
    rss_kb="$(ps -o rss= -p "$PID" 2>/dev/null | tr -d ' ')"
    if [[ -n "$rss_kb" ]]; then
        ms=$(( (SECONDS - start) * 1000 ))
        echo "$ms	$rss_kb" >>"$OUTDIR/rss.tsv"
        if (( rss_kb > peak )); then peak=$rss_kb; fi
        if (( taken < SNAPS )) \
           && (( rss_kb > last_snap_rss + (last_snap_rss * RISE / 100) )) \
           && (( rss_kb > 262144 )); then
            last_snap_rss=$rss_kb
            taken=$(( taken + 1 ))
            snapshot "$rss_kb" "$ms"
        fi
    fi
    sleep 0.2
done
wait "$PID" || echo "prof: target exited non-zero (measurement still valid)" >&2

echo
echo "prof: peak RSS $(( peak / 1024 )) MB over $(( SECONDS - start ))s"
[[ -s "$OUTDIR/snaps.tsv" ]] || { echo "prof: no snapshot was taken (target too small/fast)" >&2; exit 1; }

# Report the FATTEST snapshot — the one closest to the peak the watcher saw.
best="$(sort -k2 -n "$OUTDIR/snaps.tsv" | tail -1)"
best_ms="${best%% *}"
best_rss="${best##* }"
tag="$(printf '%06d' "$best_ms")"
echo "prof: peak snapshot ${best_ms}ms, rss $(( best_rss / 1024 )) MB"
echo -n "prof: phase: "
php tools/prof/report.php phase "$OUTDIR/stats.txt" "$best_ms"
echo
php tools/prof/report.php malloc "$OUTDIR/snap-$tag.txt" "${TOP:-40}"
echo
echo "prof: size histogram in $OUTDIR/heap-$tag.txt, RSS trace in $OUTDIR/rss.tsv"

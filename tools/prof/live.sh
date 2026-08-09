#!/usr/bin/env bash
#
# LIVE-SET attribution for a compiler run — macOS `heap`, no compiler
# instrumentation.
#
#   bash tools/prof/live.sh --build                  # produce bin/manticore.nopool first
#   bash tools/prof/live.sh [-- <manticore args>]    # default: build --apps-only manticore.json
#
# `heap <pid>` already does the attribution: it derives a name for every
# non-object block from the allocation backtrace and prints live COUNT/BYTES per
# allocating function. That takes seconds, so the watcher can snapshot the whole
# run and report the one nearest the peak. `malloc_history` answers a DIFFERENT
# question — the CALLER of a runtime allocator — and costs minutes per dump on a
# multi-GB process; run it by hand against a pid when that is the question.
#
# WHY A SEPARATE BINARY. The small-object pool carves objects and arrays out of
# ONE 1 GiB mmap (EmitLlvmRuntime.php:63) — no malloc-level tool can see inside
# it. `MANTICORE_POOL=0` is read from the environment of a COMPILER PROCESS and
# baked into the IR that process emits (Debug::initFromEnvironment), so it must
# be set while BUILDING the binary to be profiled, for the whole build (the
# allocator bodies are linkonce_odr — a half-and-half link silently keeps one of
# each). To profile some OTHER program, set it again on ITS compile line.
#
# WHAT IS STILL INVISIBLE, and why it is not a lie:
#   - string free lists (@__mir_strpool0/1) have no off switch and never return
#     memory to libc. A block parked there is STILL RESIDENT, and charging it to
#     whoever allocated it is the honest answer for peak RSS.
#   - arena chunks charge to __mir_arena_alloc, not the requester. The arena was
#     measured as barely used by this compiler; if it lands top-5, rerun with
#     MANTICORE_ARENA_ARRAYS=0 and `--memory=rc` for a control.
#
# BIN=<path>      profile any no-pool-compiled program (default: the no-pool compiler)
# OUTDIR=<dir>    keep artifacts (default: temp dir)
# EVERY=<s>       seconds between heap snapshots, default 10
# MINMB=<n>       do not snapshot below this RSS, default 128

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
# shellcheck source=tools/prof/lib.sh
source "$ROOT/tools/prof/lib.sh"
mc_export_prelude "$ROOT"

command -v heap >/dev/null 2>&1 || { echo "prof: no \`heap\` — macOS only (Linux: tools/docker/prof)" >&2; exit 1; }

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
mkdir -p "$OUTDIR"
EVERY="${EVERY:-10}"
MINMB="${MINMB:-128}"

echo "prof: $BIN ${ARGS[*]}"
echo "prof: artifacts in $OUTDIR"

# The stack-logging env var is what lets `heap` NAME the allocating function
# ("Derived N type names for non-objects from allocation backtraces"); without
# it every row is anonymous. The log lands in OUTDIR, not in the measured heap.
MallocStackLogging=1 \
MallocStackLoggingDirectory="$OUTDIR" \
MANTICORE_STATS=1 \
    "$BIN" "${ARGS[@]}" >"$OUTDIR/build.log" 2>"$OUTDIR/stats.txt" &
PID=$!

peak=0
last_at=-1000
start=$SECONDS
: >"$OUTDIR/rss.tsv"
: >"$OUTDIR/snaps.tsv"

while kill -0 "$PID" 2>/dev/null; do
    # ps prints KiB, and is asked for ONE pid: matching by binary path would also
    # match this script's own command line (the trap tools/fiber_ceiling.sh hit).
    rss_kb="$(ps -o rss= -p "$PID" 2>/dev/null | tr -d ' ')"
    if [[ -n "$rss_kb" ]]; then
        ms=$(( (SECONDS - start) * 1000 ))
        echo "$ms	$rss_kb" >>"$OUTDIR/rss.tsv"
        if (( rss_kb > peak )); then peak=$rss_kb; fi
        if (( rss_kb > MINMB * 1024 )) && (( SECONDS - last_at >= EVERY )); then
            last_at=$SECONDS
            tag="$(printf '%06d' "$ms")"
            heap "$PID" >"$OUTDIR/heap-$tag.txt" 2>/dev/null || true
            echo "$ms $rss_kb" >>"$OUTDIR/snaps.tsv"
            echo "prof: heap snapshot ${ms}ms, rss $(( rss_kb / 1024 )) MB" >&2
        fi
    fi
    sleep 0.2
done
wait "$PID" || echo "prof: target exited non-zero (measurement still valid)" >&2

echo
echo "prof: peak RSS $(( peak / 1024 )) MB over $(( SECONDS - start ))s"
[[ -s "$OUTDIR/snaps.tsv" ]] || { echo "prof: no snapshot was taken (target too small/fast)" >&2; exit 1; }

# The fattest snapshot the watcher managed to take — `heap` is fast, but the
# true peak can still fall between two of them; rss.tsv is the honest record.
best="$(sort -k2 -n "$OUTDIR/snaps.tsv" | tail -1)"
best_ms="${best%% *}"
best_rss="${best##* }"
tag="$(printf '%06d' "$best_ms")"
echo "prof: fattest snapshot ${best_ms}ms, rss $(( best_rss / 1024 )) MB"
echo -n "prof: phase: "
php tools/prof/report.php phase "$OUTDIR/stats.txt" "$best_ms"
echo
php tools/prof/report.php heap "$OUTDIR/heap-$tag.txt" "${TOP:-40}"

echo
echo "prof: RSS trace $OUTDIR/rss.tsv · $(wc -l <"$OUTDIR/snaps.tsv" | tr -d ' ') snapshots"
echo "prof: any other moment: php tools/prof/report.php heap $OUTDIR/heap-<ms>.txt"

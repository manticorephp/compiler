#!/usr/bin/env bash
#
# CPU attribution for a compiler run — macOS `sample`, zero install.
#
#   bash tools/prof/cpu.sh [-- <manticore args>]      # default: build --apps-only manticore.json
#
# Writes <outdir>/sample.txt (raw), <outdir>/stats.txt (the MANTICORE_STATS
# timeline) and prints the top self-time PHP functions.
#
# Runs the DEFAULT build — pool on, -O2, exactly the shipping codegen. (The
# no-pool binary from live.sh is for allocation attribution only; its timings
# are not this compiler's timings.)
#
# `sample` follows ONE pid, so clang/cc children are excluded by construction —
# which is what we want: this measures the compiler's own code, and clang's
# share is already known to be ~64% of a large build.
#
# OUTDIR=<dir>   keep the artifacts somewhere durable (default: a temp dir)
# INTERVAL=<ms>  sampling interval, default 1 ms
# MAXSECS=<n>    sampling ceiling, default 3600 (sample stops when the target exits)

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
# shellcheck source=tools/prof/lib.sh
source "$ROOT/tools/prof/lib.sh"
mc_export_prelude "$ROOT"

command -v sample >/dev/null 2>&1 || { echo "prof: no \`sample\` — macOS only (Linux: tools/docker/prof)" >&2; exit 1; }

ARGS=(build --apps-only manticore.json)
if [[ "${1:-}" == "--" ]]; then shift; ARGS=("$@"); fi

BIN="$ROOT/bin/manticore"
[[ -x "$BIN" ]] || { echo "prof: no $BIN" >&2; exit 1; }

OUTDIR="${OUTDIR:-$(mktemp -d)}"
mkdir -p "$OUTDIR"
INTERVAL="${INTERVAL:-1}"
MAXSECS="${MAXSECS:-3600}"

echo "prof: $BIN ${ARGS[*]}"
echo "prof: artifacts in $OUTDIR"

MANTICORE_STATS=1 "$BIN" "${ARGS[@]}" >"$OUTDIR/build.log" 2>"$OUTDIR/stats.txt" &
PID=$!

# `sample` attaches by pid, so the race is real on a fast target: give the
# process a moment to exist, and bail out cleanly if it was that fast.
sleep 0.3
if ! kill -0 "$PID" 2>/dev/null; then
    echo "prof: target already exited — nothing to sample (use a bigger input)" >&2
    wait "$PID" || true
    exit 1
fi

sample "$PID" "$MAXSECS" "$INTERVAL" -mayDie -f "$OUTDIR/sample.txt" >/dev/null 2>&1 || true
wait "$PID" || echo "prof: target exited non-zero (measurement still valid)" >&2

echo
echo "── phase timeline (MANTICORE_STATS) ──"
grep -E '^stats: ' "$OUTDIR/stats.txt" | tail -40 || true

echo
echo "── self time by PHP function ──"
php tools/prof/report.php sample "$OUTDIR/sample.txt" "${TOP:-40}"

#!/usr/bin/env bash
#
# Where does a compiled SERVER spend its time? — macOS `sample` on the worker,
# under `wrk` load, folded into PHP names by report.php (`thread` mode).
#
#   bash tools/prof/serve.sh [--build] <app-dir> [url-path]
#
#   <app-dir>   a directory with a manticore.json whose application outputs bin/app
#               (examples/symfony-app)
#   --build     (re)build <app-dir>/bin/app.prof with MANTICORE_FRAME_POINTERS=1 first.
#               Needed once: without frame records `sample` cuts every stack at the
#               first function that has none, and inclusive time means nothing.
#   url-path    the route to hammer, default /
#
# PORT=8199  SECS=10 (sampling window)  CONN=32  TOP=40
#
# WORKERS=1 on purpose: the supervisor and the worker are two processes, and the
# worker is the child. A pool thread idling in read(2) would drown the table, so
# only the main thread of the worker is reported.
#
# Artifacts go to a temp dir that is removed on exit; KEEP=1 keeps it.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BUILD=0
if [[ "${1:-}" == "--build" ]]; then BUILD=1; shift; fi
APP="${1:?usage: serve.sh [--build] <app-dir> [url-path]}"
URLPATH="${2:-/}"
command -v sample >/dev/null || { echo "serve.sh: macOS only (needs \`sample\`)" >&2; exit 1; }
command -v wrk >/dev/null || { echo "serve.sh: needs wrk (brew install wrk)" >&2; exit 1; }
cd "$APP"

if [[ $BUILD -eq 1 || ! -x bin/app.prof ]]; then
    # A scratch manifest: the same one with another output, so bin/app stays the shipping binary.
    sed 's#"bin/app"#"bin/app.prof"#' manticore.json > manticore.probe-prof.json
    MANTICORE_FRAME_POINTERS=1 "$ROOT/bin/manticore" build --quiet manticore.probe-prof.json >/dev/null
    rm -f manticore.probe-prof.json
fi

PORT="${PORT:-8199}"
SECS="${SECS:-10}"
OUT="$(mktemp -d)"
cleanup() { pkill -f "^./bin/app.prof" 2>/dev/null || true; [[ "${KEEP:-0}" == 1 ]] || rm -rf "$OUT"; }
trap cleanup EXIT

PORT=$PORT WORKERS=1 ./bin/app.prof >/dev/null 2>&1 &
for _ in $(seq 1 240); do curl -s -m 1 -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.5; done
SUP="$(pgrep -f '^./bin/app.prof' | head -1)"
WORKER="$(pgrep -P "$SUP" | head -1)"
[[ -n "$WORKER" ]] || { echo "serve.sh: no worker process under $SUP" >&2; exit 1; }
curl -sf -m 5 "http://127.0.0.1:$PORT$URLPATH" >/dev/null || echo "serve.sh: warning: $URLPATH did not answer 2xx" >&2

wrk -t2 -c"${CONN:-32}" -d$((SECS + 6))s "http://127.0.0.1:$PORT$URLPATH" >"$OUT/wrk.txt" 2>&1 &
sleep 3
sample "$WORKER" "$SECS" -file "$OUT/sample.txt" >/dev/null 2>&1
wait %2 2>/dev/null || true
grep -E 'Requests/sec|Non-2xx' "$OUT/wrk.txt" || true
php "$ROOT/tools/prof/report.php" thread "$OUT/sample.txt" "${TOP:-40}"
[[ "${KEEP:-0}" == 1 ]] && echo "artifacts: $OUT"

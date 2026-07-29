#!/usr/bin/env bash
#
# Drive tools/fiber_ceiling.php and sample the process from OUTSIDE.
#
#   tools/fiber_ceiling.sh [stack_bytes] [max_tasks] [wall_seconds]
#
# The program reports what it can see of itself (`/proc/self/status` on Linux); a
# Darwin process cannot ask for its own RSS/VSZ, so the numbers come from `ps`
# here. The wall limit is what keeps a machine-wide OOM from being the way this
# ends — the answer wanted is "what fails first", not "the box died".

set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

STACK="${1:-8388608}"
MAX="${2:-200000}"
WALL="${3:-300}"

# shellcheck source=lib/limit.sh
source "$ROOT/tools/lib/limit.sh"

BIN="$(mktemp -d)/fc"
trap 'rm -rf "$(dirname "$BIN")"' EXIT
echo "── compiling tools/fiber_ceiling.php ──"
"$ROOT/bin/manticore" compile tools/fiber_ceiling.php -o "$BIN" || exit 1

echo "── stack=$STACK max=$MAX wall=${WALL}s ──"
mc_limit "$WALL" "$BIN" "$STACK" "$MAX" &
runner=$!

# Sample at 5 Hz: peak RSS and VSZ, from the outside. Slower than that and a run
# of a few seconds is missed entirely — the first attempt at this reported 2 MiB
# for a 20 000-fiber run because every sample landed on the wrapper.
peak_rss=0 peak_vsz=0
while kill -0 "$runner" 2>/dev/null; do
    # The compiled binary is a grandchild of this shell under some limit backends,
    # so match by path rather than by pid — and take the LARGEST match: the perl
    # wrapper's own command line contains the same path, and its 3 MiB was being
    # reported as the peak of a 30 000-fiber run.
    read -r rss vsz <<<"$(ps -ax -o rss=,vsz=,command= \
        | awk -v b="$BIN" '$0 ~ b && $0 !~ /awk/ && $1 > r { r = $1; v = $2 }
                           END { print r, v }')"
    if [[ -n "${rss:-}" ]]; then
        [[ $rss -gt $peak_rss ]] && peak_rss=$rss
        [[ $vsz -gt $peak_vsz ]] && peak_vsz=$vsz
    fi
    sleep 0.2
done
wait "$runner"; rc=$?

echo "── peak RSS $((peak_rss / 1024)) MiB · peak VSZ $((peak_vsz / 1024)) MiB · rc=$rc ──"
[[ $rc -eq 124 ]] && echo "(hit the ${WALL}s wall limit before any allocation failed)"
exit 0

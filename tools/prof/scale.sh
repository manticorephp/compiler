#!/usr/bin/env bash
#
# Instalment 0 of the profiling harness: DOES THE PEAK TRACK THE INPUT?
#
#   bash tools/prof/scale.sh [stride ...]     # default: 4 2 1
#
# Builds the compiler application from 1/4, 1/2 and all of src/ and reports peak
# RSS for each. Nothing is instrumented; this is `/usr/bin/time` and a manifest.
#
# Reading the result:
#   RSS roughly LINEAR in kept bytes  -> the peak is per-file retention; the fix
#                                        is releasing/streaming, and attribution
#                                        should hunt for what stays live per file.
#   RSS roughly FLAT                  -> the fixed pipeline cost (prelude, stdlib
#                                        sig, monomorphization) dominates, and
#                                        attribution must target that instead.
#
# The build runs with --apps-only: the library half would rebuild lib/*.o with a
# HALF source tree and poison the checkout (a failed/partial build poisons
# bin/manticore + lib/*.o for every later measurement).

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
# shellcheck source=tools/prof/lib.sh
source "$ROOT/tools/prof/lib.sh"
mc_export_prelude "$ROOT"

STRIDES=("$@")
if [[ ${#STRIDES[@]} -eq 0 ]]; then STRIDES=(4 2 1); fi

BIN="$ROOT/bin/manticore"
[[ -x "$BIN" ]] || { echo "prof: no $BIN — seed the worktree first" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

printf '%-8s %-10s %-12s %-10s %-10s %s\n' stride files src-KB wall-s peak-MB MB/src-KB
for s in "${STRIDES[@]}"; do
    read -r kept total bytes < <(php tools/prof/scale_manifest.php "$s" "$WORK/m$s.json" "$WORK/out$s")
    t0=$SECONDS
    rss="$(mc_max_rss_bytes "$BIN" build --apps-only "$WORK/m$s.json")"
    wall=$(( SECONDS - t0 ))
    kb=$(( bytes / 1024 ))
    mb="$(mc_mb "$rss")"
    printf '%-8s %-10s %-12s %-10s %-10s %s\n' \
        "1/$s" "$kept/$total" "$kb" "$wall" "$mb" \
        "$(awk -v m="$mb" -v k="$kb" 'BEGIN{printf "%.3f", (k>0)? m/k : 0}')"
done

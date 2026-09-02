#!/usr/bin/env bash
#
# Bounded MEMORY diagnostic for one audit-tier build — direct-PID, capped.
#
#   bash tools/prof/tier.sh 3                       # symfony-demo-probe tier T3
#   bash tools/prof/tier.sh 6                       # T6 (doctrine) — expect the cap
#   bash tools/prof/tier.sh path/to/manticore.json  # any manifest
#
# Every g135–g152 Doctrine measurement was made with a throwaway /tmp script
# that no longer exists, so none of that ladder is reproducible. This is that
# harness, in the repo.
#
# ⚠ THE COMPILER IS THE BACKGROUND PID, never a `/usr/bin/time` child. An
# earlier monitor sampled the WRAPPER instead: its vmmap records read "880K",
# and the configured ceiling never applied to the real process. Every number
# from that era is void. Do not reintroduce a wrapper here — mc_max_rss_bytes
# exists in lib.sh for whole-run peaks, and is the wrong tool for this job.
#
# Writes to <outdir>:
#   samples.tsv  epoch  pid  rss_kb  physical_bytes  vmmap_line   (one per tick)
#   build.log    compiler stdout+stderr
#   vmmap.txt    `vmmap -summary` at the cap or at exit
#   sample.txt   `sample` stack profile at the cap (only when the cap is hit)
#   status       one summary line, also printed
#
# CAP_GB=<n>    Memory ceiling in GiB; the compiler gets SIGTERM above it, judged
#               on max(rss, physical footprint) — NOT rss alone, which collapses
#               under memory pressure while the footprint climbs. Default 8.
#               A run that reaches the cap is a CAPPED DIAGNOSTIC, never a build
#               result — it produced no staged IR, no clang object, no binary.
# EVERY=<s>     seconds between ticks, default 6 (a `vmmap -summary` per tick is
#               not free on a multi-GB process)
# OUTDIR=<dir>  keep artifacts somewhere durable (default: a temp dir)
# APP=<dir>     fixture root holding manticore.t<N>.json
# BIN=<path>    compiler to measure (default: this checkout's bin/manticore)
#
# Any MANTICORE_* already in the environment is inherited. The Doctrine ladder
# used MANTICORE_POOL=0 MANTICORE_MEMORY=rc MANTICORE_PHASE_RECLAIM=1; POOL is
# compile-time, so a pool-off READING needs a pool-off BINARY (tools/prof/live.sh
# --build), not just this variable.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
# shellcheck source=tools/prof/lib.sh
source "$ROOT/tools/prof/lib.sh"
mc_export_prelude "$ROOT"

command -v vmmap >/dev/null 2>&1 && command -v sample >/dev/null 2>&1 || { echo "prof: needs macOS \`vmmap\`+\`sample\` (Linux: tools/docker/prof)" >&2; exit 1; }

[[ $# -ge 1 ]] || { echo "usage: tools/prof/tier.sh <tier|manifest> [-- <manticore args>]" >&2; exit 2; }
TARGET="$1"; shift
EXTRA=()
if [[ "${1:-}" == "--" ]]; then shift; EXTRA=("$@"); fi

APP="${APP:-/Users/taras/var/projects/symfony-demo-probe/app}"
if [[ "$TARGET" =~ ^[1-8]$ ]]; then
    MANIFEST="$APP/manticore.t$TARGET.json"
    LABEL="t$TARGET"
else
    MANIFEST="$TARGET"
    LABEL="$(basename "$MANIFEST" .json)"
fi
[[ -f "$MANIFEST" ]] || { echo "prof: no manifest $MANIFEST" >&2; exit 1; }

BIN="${BIN:-$ROOT/bin/manticore}"
[[ -x "$BIN" ]] || { echo "prof: no $BIN" >&2; exit 1; }

CAP_GB="${CAP_GB:-8}"
CAP_BYTES=$(( CAP_GB * 1024 * 1024 * 1024 ))
EVERY="${EVERY:-6}"
OUTDIR="${OUTDIR:-$(mktemp -d "/tmp/manticore-tier-$LABEL-XXXXXX")}"
mkdir -p "$OUTDIR"
TSV="$OUTDIR/samples.tsv"
printf 'epoch\tpid\trss_kb\tphysical_bytes\tvmmap_line\n' >"$TSV"

echo "prof: $LABEL  manifest=$MANIFEST"
echo "prof: compiler=$BIN  cap=${CAP_GB}GiB  every=${EVERY}s"
echo "prof: artifacts in $OUTDIR"

START=$(date +%s)
# The build must run from the fixture root: manifest paths are relative to it.
# `set -u` makes an EMPTY array expansion an error on the bash 3.2 macOS ships,
# so the no-extra-args case must not spell "${EXTRA[@]}" bare.
( cd "$(dirname "$MANIFEST")" && exec "$BIN" build ${EXTRA[@]+"${EXTRA[@]}"} "$(basename "$MANIFEST")" ) \
    >"$OUTDIR/build.log" 2>&1 &
PID=$!
echo "prof: direct pid=$PID"

CAPPED=0
PEAK_RSS=0
PEAK_PHYS=0
while kill -0 "$PID" 2>/dev/null; do
    RSS_KB=$(ps -o rss= -p "$PID" 2>/dev/null | tr -d ' ')
    [[ -n "$RSS_KB" ]] || break
    VM_LINE=$(vmmap -summary "$PID" 2>/dev/null | grep -m1 'Physical footprint:' || true)
    PHYS=$(printf '%s' "$VM_LINE" | awk '{
        v=$3; u=substr(v,length(v),1); n=substr(v,1,length(v)-1)+0
        if (u=="G") printf "%d", n*1073741824
        else if (u=="M") printf "%d", n*1048576
        else if (u=="K") printf "%d", n*1024
        else printf "0"
    }')
    [[ -n "$PHYS" ]] || PHYS=0
    printf '%s\t%s\t%s\t%s\t%s\n' "$(date +%s)" "$PID" "$RSS_KB" "$PHYS" "$VM_LINE" >>"$TSV"
    (( RSS_KB * 1024 > PEAK_RSS )) && PEAK_RSS=$(( RSS_KB * 1024 ))
    (( PHYS > PEAK_PHYS )) && PEAK_PHYS=$PHYS

    # ⚠ THE CEILING MUST JUDGE THE LARGER OF THE TWO. `ps rss` is not the
    # process's memory footprint under pressure: the kernel COMPRESSES pages, and
    # rss then FALLS while the real footprint keeps climbing. A cap-20 T6 run was
    # sampled at rss=2.9GB / footprint=26.4GB and sailed straight past the
    # ceiling to 40.8GB on a 32GB machine — the same failure as the
    # `/usr/bin/time` wrapper era (the ceiling silently never applied), with a
    # different cause. `Physical footprint` is what Apple's own accounting uses,
    # and this loop already samples it.
    BIG=$(( RSS_KB * 1024 ))
    (( PHYS > BIG )) && BIG=$PHYS
    if (( BIG > CAP_BYTES )); then
        CAPPED=1
        echo "prof: CAP HIT rss=${RSS_KB}kB phys=${PHYS}B (judged ${BIG}B) > ${CAP_GB}GiB — capturing vmmap + sample, then SIGTERM"
        vmmap -summary "$PID" >"$OUTDIR/vmmap.txt" 2>&1 || true
        sample "$PID" 5 1 -mayDie -f "$OUTDIR/sample.txt" >/dev/null 2>&1 || true
        kill -TERM "$PID" 2>/dev/null || true
        break
    fi
    sleep "$EVERY"
done

# A run that ends on its own still deserves a final vmmap, so a SUCCESS and a
# CAP are described by the same artifact set.
if (( CAPPED == 0 )) && kill -0 "$PID" 2>/dev/null; then
    vmmap -summary "$PID" >"$OUTDIR/vmmap.txt" 2>&1 || true
fi

RC=0
wait "$PID" || RC=$?
ELAPSED=$(( $(date +%s) - START ))

if (( CAPPED == 1 )); then
    STATUS="capped"
else
    STATUS=$([[ $RC -eq 0 ]] && echo "ok" || echo "failed")
fi
LINE="status=$STATUS rc=$RC elapsed_s=$ELAPSED peak_rss_bytes=$PEAK_RSS peak_physical_bytes=$PEAK_PHYS label=$LABEL"
printf '%s\n' "$LINE" | tee "$OUTDIR/status"
echo "prof: artifacts in $OUTDIR"

# A capped run produced NO staged IR, NO clang object and NO binary. Say so
# here rather than letting a smaller peak read as progress.
if (( CAPPED == 1 )); then
    echo "prof: CAPPED DIAGNOSTIC — no staged IR, no clang object, no linked binary." >&2
    exit 143
fi
exit "$RC"

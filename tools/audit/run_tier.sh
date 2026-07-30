#!/usr/bin/env bash
#
# run_tier.sh <N> — run the audit's measurement lanes over tier N.
#
# Lane (a) STATIC   `analyze --json` over the cumulative closed world, then a
#                   second `--deep` run that drives the compiler's own MIR type
#                   passes. Separate invocations on purpose: --deep lowers the
#                   code and can itself die, and the shallow result must already
#                   be banked when it does.
#
#                   Sees: undefined symbols, arg count/type, return type.
#                   Cannot see: anything semantic (that is the probe suite),
#                   anything behind a dynamic call, anything about runtime
#                   correctness. A clean analyze is NOT "it works".
#
# Lane (b) BUILD    a manifest build with STUBS_PREFIX set, whose generated
#                   <prefix>_stubs.c lists every symbol the LIVE, post-DCE
#                   program references and cannot resolve. Strictly stronger
#                   evidence than lane (a) -- but blind to everything that
#                   links, which includes every bare-alias capture.
#
#                   ⚠ Must be a MANIFEST build. `manticore compile` links with a
#                   plain cc and hard-fails on a missing symbol; only the
#                   manifest path calls link_stubs.sh.
#
# Findings are written under docs/audit/data/. A crash is captured, not fixed.
#
# Usage: bash tools/audit/run_tier.sh <N> [--skip-deep] [--skip-build]

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

TIER="${1:-}"
[ -z "$TIER" ] && { echo "usage: run_tier.sh <N>" >&2; exit 2; }
shift || true
SKIP_DEEP=0; SKIP_BUILD=0
for a in "$@"; do
    [ "$a" = "--skip-deep" ] && SKIP_DEEP=1
    [ "$a" = "--skip-build" ] && SKIP_BUILD=1
done

APP=/Users/taras/var/projects/symfony-demo-probe/app
PROBE=/Users/taras/var/projects/symfony-demo-probe
DATA="$ROOT/docs/audit/data"
mkdir -p "$DATA" "$PROBE/audit-data"

export MANTICORE_PRELUDE="$ROOT/prelude"
MC="$ROOT/bin/manticore"

# `mapfile` is bash 4; macOS ships bash 3.2, where it silently does not exist
# and every later ${DIRS[@]} expands to an unbound-variable error.
DIRS=()
while IFS= read -r line; do
    [ -n "$line" ] && DIRS+=("$line")
done < <(php tools/audit/tier_dirs.php "$TIER" --app "$APP")
echo "== T$TIER: ${#DIRS[@]} cumulative dirs =="

echo "-- lane (a) analyze --"
"$MC" analyze --json "${DIRS[@]}" > "$DATA/analyze-t$TIER.json" 2> "$PROBE/audit-data/analyze-t$TIER.err"
RC=$?
echo "   analyze rc=$RC  $(wc -c < "$DATA/analyze-t$TIER.json") bytes"
# rc 1 just means "some diagnostic was error-severity", which is the point.
# Anything else is the tool dying on vendor code -- a Class-0 blocker.
if [ "$RC" -ne 0 ] && [ "$RC" -ne 1 ]; then
    echo "   CRASH analyze rc=$RC — Class 0, blocks the audit itself"
    tail -5 "$PROBE/audit-data/analyze-t$TIER.err"
fi

php -r '
$f = $argv[1];
$d = json_decode((string)@file_get_contents($f), true);
if (!is_array($d)) { echo "   (no parseable json)\n"; exit; }
$by = [];
foreach ($d as $x) { $c = (string)($x["code"] ?? "?"); $by[$c] = ($by[$c] ?? 0) + 1; }
arsort($by);
foreach ($by as $c => $n) { printf("   %-26s %d\n", $c, $n); }
' "$DATA/analyze-t$TIER.json"

if [ "$SKIP_DEEP" -eq 0 ]; then
    echo "-- lane (a) analyze --deep --"
    "$MC" analyze --deep --json "${DIRS[@]}" > "$DATA/analyze-deep-t$TIER.json" 2> "$PROBE/audit-data/deep-t$TIER.err"
    RC=$?
    echo "   deep rc=$RC  $(wc -c < "$DATA/analyze-deep-t$TIER.json") bytes"
    if [ "$RC" -ne 0 ] && [ "$RC" -ne 1 ]; then
        echo "   CRASH analyze --deep rc=$RC — record as S2 compiler-crash, route around, do NOT fix"
        tail -5 "$PROBE/audit-data/deep-t$TIER.err"
    fi
fi

if [ "$SKIP_BUILD" -eq 0 ]; then
    echo "-- lane (b) manifest build + stubs --"
    php tools/audit/gen_manifest.php "$TIER" --app "$APP" --out "$PROBE/manticore.t$TIER.json" || exit 1
    ( cd "$PROBE" && STUBS_PREFIX="$PROBE/audit-data/t$TIER" \
        "$MC" build "manticore.t$TIER.json" > "$PROBE/audit-data/build-t$TIER.log" 2>&1 )
    RC=$?
    echo "   build rc=$RC"
    if [ -f "$PROBE/audit-data/t${TIER}_stubs.c" ]; then
        php tools/audit/demangle_stubs.php "$PROBE/audit-data/t${TIER}_stubs.c" \
            > "$DATA/stubs-t$TIER.txt"
        echo "   $(wc -l < "$DATA/stubs-t$TIER.txt") missing symbol(s) -> docs/audit/data/stubs-t$TIER.txt"
    else
        echo "   no stubs.c — build did not reach the link stage"
        tail -12 "$PROBE/audit-data/build-t$TIER.log"
    fi
fi

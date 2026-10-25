#!/usr/bin/env bash
#
# Strict-mode invariance: plain `dump-ast` output must not change.
#
# Usage:
#   tests/parser/strict_invariance.sh --record DIR   # write DIR/shas.txt
#   tests/parser/strict_invariance.sh --check DIR    # compare against it
#
# One sha1 per .php under src/, prelude/ and tests/aot/cases/. DIR must be
# outside the repo. Numeric enum-case name/value lines are masked: dump-ast prints
# uninitialised memory there, differing run to run (pre-existing bug).

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MODE="${1:-}"
DIR="${2:-}"
if [[ ( "$MODE" != "--record" && "$MODE" != "--check" ) || -z "$DIR" ]]; then
    sed -n '2,10p' "$0"
    exit 2
fi

mkdir -p "$DIR"
out="$DIR/shas.txt"
[[ "$MODE" == "--check" ]] && out="$DIR/shas.check.txt"

: > "$out"
while IFS= read -r f; do
    sha="$(bin/manticore dump-ast "$f" 2>&1 | LC_ALL=C awk '/^ *Case -?[0-9]+$/ {print "Case N"; m=1; next} m && /^ *value: -?[0-9]+$/ {print "value N"; m=0; next} {m=0; print}' | shasum | cut -d' ' -f1)"
    printf '%s  %s\n' "$sha" "$f" >> "$out"
done < <(find src prelude tests/aot/cases -name '*.php' | LC_ALL=C sort)

printf 'files: %d\n' "$(wc -l < "$out")"
if [[ "$MODE" == "--check" ]]; then
    if diff -q "$DIR/shas.txt" "$out" > /dev/null; then
        echo "strict invariance: OK"
        rm -f "$out"
    else
        echo "strict invariance: DIFF"
        diff "$DIR/shas.txt" "$out" | head -n 20
        exit 1
    fi
fi

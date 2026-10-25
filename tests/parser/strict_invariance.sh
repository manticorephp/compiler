#!/usr/bin/env bash
#
# Strict-mode invariance: plain `dump-ast` output must not change.
#
# Usage:
#   tests/parser/strict_invariance.sh --record DIR   # freeze a corpus + write DIR/shas.txt
#   tests/parser/strict_invariance.sh --check DIR [--corpus DIR2]   # compare against it
#
# --record exports src/, prelude/ and tests/aot/cases/ from git at the base commit
# into DIR/corpus (DIR must be outside the repo), dumps every file twice and drops
# files whose two dumps differ (listed in DIR/unstable.txt: dump-ast prints
# uninitialised memory for some inputs). --check re-runs the CURRENT bin/manticore
# on the frozen corpus (or --corpus DIR2, e.g. the live tree). Numeric enum-case
# name/value lines are masked.

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BASE=579840ed

MODE="${1:-}"
DIR="${2:-}"
CORPUS=""
if [[ "${3:-}" == "--corpus" ]]; then CORPUS="${4:-}"; fi
if [[ ( "$MODE" != "--record" && "$MODE" != "--check" ) || -z "$DIR" ]]; then
    sed -n '2,13p' "$0"
    exit 2
fi

mkdir -p "$DIR"
if [[ "$MODE" == "--record" ]]; then
    rm -rf "$DIR/corpus"
    mkdir -p "$DIR/corpus"
    (cd "$ROOT" && git archive "$BASE" src prelude tests/aot/cases) | tar -x -C "$DIR/corpus"
fi
if [[ -z "$CORPUS" ]]; then CORPUS="$DIR/corpus"; fi
[[ "$CORPUS" == "$ROOT" ]] && CORPUS="$ROOT"

dump_sha() {
    "$ROOT/bin/manticore" dump-ast "$1" 2>&1 | LC_ALL=C awk '/^ *Case -?[0-9]+$/ {print "Case N"; m=1; next} m && /^ *value: -?[0-9]+$/ {print "value N"; m=0; next} {m=0; print}' | shasum | cut -d' ' -f1
}

out="$DIR/shas.txt"
[[ "$MODE" == "--check" ]] && out="$DIR/shas.check.txt"
: > "$out"
if [[ "$MODE" == "--record" ]]; then : > "$DIR/unstable.txt"; fi

cd "$CORPUS"
while IFS= read -r f; do
    sha="$(dump_sha "$f")"
    if [[ "$MODE" == "--record" && "$sha" != "$(dump_sha "$f")" ]]; then
        echo "$f" >> "$DIR/unstable.txt"
        continue
    fi
    if [[ "$MODE" == "--check" ]] && /usr/bin/grep -qxF "$f" "$DIR/unstable.txt"; then
        continue
    fi
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

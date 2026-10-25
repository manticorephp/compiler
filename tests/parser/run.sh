#!/usr/bin/env bash
#
# Manticore parser test runner.
#
# Runs `bin/manticore dump-ast --tolerant --ranges` on every case under
# tests/parser/cases and diffs stdout against tests/parser/expected/<name>.txt.
# Cases named *.strict.php run plain `dump-ast` (no --tolerant/--ranges).
# Cases are invoked with a path RELATIVE to the repo root.
#
# Usage:
#   tests/parser/run.sh                # all cases
#   tests/parser/run.sh valid          # filter substring
#   tests/parser/run.sh -k valid       # filter substring
#   tests/parser/run.sh -v             # verbose: show full diff on fail

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MANTICORE="$ROOT/bin/manticore"
CASES="$ROOT/tests/parser/cases"
EXPECTED="$ROOT/tests/parser/expected"
WORK="$ROOT/tests/parser/.work"
LIMIT=60

VERBOSE=0
FILTER=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose) VERBOSE=1; shift ;;
        -k|--filter)  FILTER="$2"; shift 2 ;;
        -h|--help)    sed -n '2,15p' "$0"; exit 0 ;;
        *) FILTER="$1"; shift ;;
    esac
done

if [[ ! -x "$MANTICORE" ]]; then
    echo "fatal: $MANTICORE not built; run bin/build first" >&2
    exit 1
fi

mkdir -p "$WORK"

# No `timeout` on macOS: kill the child after $LIMIT seconds.
run_limited() {
    "$@" &
    local pid=$!
    ( sleep "$LIMIT"; kill -9 "$pid" 2>/dev/null ) &
    local wd=$!
    wait "$pid" 2>/dev/null
    local rc=$?
    kill "$wd" 2>/dev/null
    wait "$wd" 2>/dev/null
    return $rc
}

cases=()
for f in "$CASES"/*.php; do
    [[ -f "$f" ]] || continue
    n="$(basename "$f" .php)"
    [[ -n "$FILTER" && "$n" != *"$FILTER"* ]] && continue
    cases+=("$n")
done

if [[ ${#cases[@]} -eq 0 ]]; then
    echo "no cases match filter '$FILTER'" >&2
    exit 1
fi

passed=0
failed=0
failed_names=()

for name in "${cases[@]}"; do
    expected="$EXPECTED/$name.txt"
    actual="$WORK/$name.txt"
    rel="tests/parser/cases/$name.php"

    if [[ ! -f "$expected" ]]; then
        echo "SKIP $name (no expected output)"
        continue
    fi

    flags="--tolerant --ranges"
    [[ "$name" == *.strict ]] && flags=""

    # exit code ignored: only the printed text is compared
    run_limited "$MANTICORE" dump-ast $flags "$rel" > "$actual" 2>&1

    if diff -q "$expected" "$actual" > /dev/null 2>&1; then
        passed=$((passed + 1))
        printf 'PASS %s\n' "$name"
    else
        failed=$((failed + 1))
        failed_names+=("$name")
        printf 'FAIL %s  (output mismatch)\n' "$name"
        if [[ $VERBOSE -eq 1 ]]; then
            echo "      ---- expected ----"; sed 's/^/      /' "$expected"
            echo "      ---- actual ----";   head -n 80 "$actual" | sed 's/^/      /'
        else
            (diff "$expected" "$actual" || true) | head -6 | sed 's/^/      /' || true
        fi
    fi
done

echo "---"
printf 'passed: %d  failed: %d  total: %d\n' "$passed" "$failed" "${#cases[@]}"
if [[ $failed -gt 0 ]]; then
    printf 'failures: %s\n' "${failed_names[*]}"
    exit 1
fi

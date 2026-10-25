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

    # range invariants: --check-ranges must print nothing and exit 0
    range_bad=0
    if [[ "$name" != *.strict ]]; then
        chk="$WORK/$name.check"
        run_limited "$MANTICORE" dump-ast --tolerant --check-ranges "$rel" > "$chk" 2>&1
        [[ $? -ne 0 || -s "$chk" ]] && range_bad=1
    fi

    if diff -q "$expected" "$actual" > /dev/null 2>&1 && [[ $range_bad -eq 0 ]]; then
        passed=$((passed + 1))
        printf 'PASS %s\n' "$name"
    elif [[ $range_bad -eq 1 ]] && diff -q "$expected" "$actual" > /dev/null 2>&1; then
        failed=$((failed + 1))
        failed_names+=("$name")
        printf 'FAIL %s  (--check-ranges not clean)\n' "$name"
        head -n 6 "$WORK/$name.check" | sed 's/^/      /'
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

# RangeCheck self-test on hand-built trees (needs the Zend php; SKIP without it)
if command -v php > /dev/null 2>&1; then
    if [[ -z "$FILTER" || "rangecheck_selftest" == *"$FILTER"* ]]; then
        if php -d xdebug.mode=off "$ROOT/tests/parser/rangecheck_selftest.php" > "$WORK/selftest.txt" 2>&1; then
            passed=$((passed + 1)); printf 'PASS rangecheck_selftest\n'
        else
            failed=$((failed + 1)); failed_names+=("rangecheck_selftest")
            printf 'FAIL rangecheck_selftest\n'; head -n 10 "$WORK/selftest.txt" | sed 's/^/      /'
        fi
    fi
else
    echo "SKIP rangecheck_selftest (php not found)"
fi

echo "---"
printf 'passed: %d  failed: %d  total: %d\n' "$passed" "$failed" "$((passed + failed))"
if [[ $failed -gt 0 ]]; then
    printf 'failures: %s\n' "${failed_names[*]}"
    exit 1
fi

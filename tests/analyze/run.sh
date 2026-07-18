#!/usr/bin/env bash
#
# Manticore analyzer test runner.
#
# Runs `bin/manticore analyze` on every case under tests/analyze/cases and
# diffs its stdout against the matching .txt file under tests/analyze/expected.
#
# Unlike the AOT suite this asserts DIAGNOSTIC OUTPUT, not runtime behaviour —
# so a non-zero analyze exit (errors were reported) is expected, not a failure;
# only the printed text is compared.
#
# Cases are invoked with a path RELATIVE to the repo root so the file column in
# each diagnostic (`tests/analyze/cases/<name>.php:...`) is machine-independent.
#
# Usage:
#   tests/analyze/run.sh                # all cases
#   tests/analyze/run.sh clean          # single case
#   tests/analyze/run.sh -k undefined   # filter substring
#   tests/analyze/run.sh -v             # verbose: show full diff on fail
#
# Case shapes:
#   - cases/<name>.php   → single-file analyze
#   - cases/<name>/      → directory; recursive *.php scan

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MANTICORE="$ROOT/bin/manticore"
CASES="$ROOT/tests/analyze/cases"
EXPECTED="$ROOT/tests/analyze/expected"
WORK="$ROOT/tests/analyze/.work"

VERBOSE=0
FILTER=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose) VERBOSE=1; shift ;;
        -k|--filter)  FILTER="$2"; shift 2 ;;
        -h|--help)    sed -n '2,20p' "$0"; exit 0 ;;
        *) FILTER="$1"; shift ;;
    esac
done

if [[ ! -x "$MANTICORE" ]]; then
    echo "fatal: $MANTICORE not built; run bin/compile first" >&2
    exit 1
fi

mkdir -p "$WORK"

cases=()
for f in "$CASES"/*.php; do
    [[ -f "$f" ]] || continue
    cases+=("$(basename "$f" .php)")
done
for d in "$CASES"/*/; do
    [[ -d "$d" ]] || continue
    cases+=("$(basename "$d")")
done

if [[ -n "$FILTER" ]]; then
    filtered=()
    for c in "${cases[@]}"; do
        [[ "$c" == *"$FILTER"* ]] && filtered+=("$c")
    done
    cases=("${filtered[@]}")
fi

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

    if [[ ! -f "$expected" ]]; then
        echo "SKIP $name (no expected output)"
        continue
    fi

    rel="tests/analyze/cases/$name.php"
    [[ -d "$CASES/$name" ]] && rel="tests/analyze/cases/$name"

    # Optional per-case flags (e.g. `--deep`) in cases/<name>.flags.
    flags=""
    [[ -f "$CASES/$name.flags" ]] && flags="$(cat "$CASES/$name.flags")"

    # Analyze exits 1 when it reports errors — that is a normal outcome here,
    # so the exit code is ignored and only stdout is compared.
    "$MANTICORE" analyze $flags "$rel" > "$actual" 2>/dev/null

    if diff -q "$expected" "$actual" > /dev/null 2>&1; then
        passed=$((passed + 1))
        printf 'PASS %s\n' "$name"
    else
        failed=$((failed + 1))
        failed_names+=("$name")
        printf 'FAIL %s  (output mismatch)\n' "$name"
        if [[ $VERBOSE -eq 1 ]]; then
            echo "      ---- expected ----"; sed 's/^/      /' "$expected"
            echo "      ---- actual ----";   sed 's/^/      /' "$actual"
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

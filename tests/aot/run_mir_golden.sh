#!/usr/bin/env bash
#
# MIR golden-dump test runner.
#
# For every case under tests/aot/mir/cases, runs `bin/manticore
# dump-mir <case>` and diffs the typed-IR text against the matching
# .mir golden under tests/aot/mir/expected. Snapshots the AST -> MIR
# lowering itself (not just program output) so a refactor that
# silently changes MIR shape is caught at review time.
#
# Usage:
#   tests/aot/run_mir_golden.sh            # run all cases
#   tests/aot/run_mir_golden.sh -k arith   # filter substring
#   tests/aot/run_mir_golden.sh --bless    # (re)generate goldens
#   tests/aot/run_mir_golden.sh -v         # show diff on fail
#
# A case is `cases/<name>.php`; its golden is `expected/<name>.mir`.
# The built-in Exception/Error prelude is hidden by default, so
# goldens track only user functions + __main.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MANTICORE="$ROOT/bin/manticore"
CASES="$ROOT/tests/aot/mir/cases"
EXPECTED="$ROOT/tests/aot/mir/expected"

BLESS=0
VERBOSE=0
FILTER=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --bless)      BLESS=1; shift ;;
        -v|--verbose) VERBOSE=1; shift ;;
        -k|--filter)  FILTER="$2"; shift 2 ;;
        -h|--help)    sed -n '2,20p' "$0"; exit 0 ;;
        *)            FILTER="$1"; shift ;;
    esac
done

if [[ ! -x "$MANTICORE" ]]; then
    echo "fatal: $MANTICORE not built; run bin/compile first" >&2
    exit 1
fi

mkdir -p "$EXPECTED"

pass=0
fail=0
blessed=0
failed_names=()

for f in "$CASES"/*.php; do
    [[ -f "$f" ]] || continue
    name="$(basename "$f" .php)"
    [[ -n "$FILTER" && "$name" != *"$FILTER"* ]] && continue

    # Three goldens per case, locking the whole memory contract:
    #   .mir      plain typed IR (default hybrid memory model)
    #   .eff.mir  effects + alloc-kind + MemoryOps, default hybrid
    #   .rc.mir   effects under --memory=rc (NoRefcount release path)
    for variant in plain effects rc; do
        if [[ "$variant" == "plain" ]]; then
            golden="$EXPECTED/$name.mir"
            actual="$("$MANTICORE" dump-mir "$f" 2>/dev/null || true)"
            tag="$name"
        elif [[ "$variant" == "effects" ]]; then
            golden="$EXPECTED/$name.eff.mir"
            actual="$("$MANTICORE" dump-mir --effects "$f" 2>/dev/null || true)"
            tag="$name (effects)"
        else
            golden="$EXPECTED/$name.rc.mir"
            actual="$("$MANTICORE" dump-mir --effects --memory=rc "$f" 2>/dev/null || true)"
            tag="$name (rc)"
        fi

        if [[ "$BLESS" -eq 1 ]]; then
            printf '%s\n' "$actual" > "$golden"
            echo "blessed $tag"
            blessed=$((blessed + 1))
            continue
        fi

        if [[ ! -f "$golden" ]]; then
            echo "MISSING golden: $tag (run --bless)"
            fail=$((fail + 1))
            failed_names+=("$tag")
            continue
        fi

        if diff -q <(printf '%s\n' "$actual") "$golden" >/dev/null 2>&1; then
            pass=$((pass + 1))
        else
            echo "FAIL $tag"
            fail=$((fail + 1))
            failed_names+=("$tag")
            if [[ "$VERBOSE" -eq 1 ]]; then
                diff <(printf '%s\n' "$actual") "$golden" || true
            fi
        fi
    done
done

if [[ "$BLESS" -eq 1 ]]; then
    echo "blessed $blessed golden(s)"
    exit 0
fi

echo "----"
echo "mir golden: $pass passed, $fail failed"
if [[ "$fail" -gt 0 ]]; then
    echo "failing: ${failed_names[*]}"
    exit 1
fi

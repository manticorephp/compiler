#!/usr/bin/env bash
#
# Manticore AOT test runner.
#
# Compiles every case under tests/aot/cases via `bin/manticore`,
# runs the resulting binary, and diffs stdout against the matching
# .out file under tests/aot/expected.
#
# Usage:
#   tests/aot/run.sh                # all cases
#   tests/aot/run.sh echo_int       # single case
#   tests/aot/run.sh -k union       # filter substring
#   tests/aot/run.sh -v             # verbose: show stderr / IR on fail
#
# Case shapes:
#   - cases/<name>.php              → single-file compile
#   - cases/<name>/                 → directory; compiled from cwd <name>
#                                     so `.manticore.php` manifests resolve

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MANTICORE="$ROOT/bin/manticore"
CASES="$ROOT/tests/aot/cases"
EXPECTED="$ROOT/tests/aot/expected"
WORK="$ROOT/tests/aot/.work"

VERBOSE=0
FILTER=""
BACKEND_ARGS=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose) VERBOSE=1; shift ;;
        -m|--mir)     BACKEND_ARGS="--backend=mir"; shift ;;
        -k|--filter)  FILTER="$2"; shift 2 ;;
        -h|--help)
            sed -n '2,20p' "$0"
            exit 0
            ;;
        *) FILTER="$1"; shift ;;
    esac
done

if [[ ! -x "$MANTICORE" ]]; then
    echo "fatal: $MANTICORE not built; run bin/compile first" >&2
    exit 1
fi

mkdir -p "$WORK"

# Discover cases: every .php in cases/ and every subdir.
cases=()
for f in "$CASES"/*.php; do
    [[ -f "$f" ]] || continue
    name="$(basename "$f" .php)"
    cases+=("$name")
done
for d in "$CASES"/*/; do
    [[ -d "$d" ]] || continue
    name="$(basename "$d")"
    cases+=("$name")
done

# Apply filter.
if [[ -n "$FILTER" ]]; then
    filtered=()
    for c in "${cases[@]}"; do
        if [[ "$c" == *"$FILTER"* ]]; then
            filtered+=("$c")
        fi
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
    expected="$EXPECTED/$name.out"
    bin="$WORK/$name.bin"
    stderr_log="$WORK/$name.stderr"
    actual="$WORK/$name.out"

    if [[ ! -f "$expected" ]]; then
        echo "SKIP $name (no expected output)"
        continue
    fi

    # Wipe any stale binary from a previous run so a silently-failing
    # compile (manticore returns 0 but produces nothing) doesn't fall
    # through to running the previous build — that masked a real
    # regression in the past.
    rm -f "$bin"

    # Compile: single-file vs directory.
    src="$CASES/$name.php"
    if [[ -d "$CASES/$name" ]]; then
        # cd into the case dir so .manticore.php / relative paths
        # resolve as the case author wrote them.
        (cd "$CASES/$name" && "$MANTICORE" compile $BACKEND_ARGS -o "$bin") > "$stderr_log" 2>&1 || {
            failed=$((failed + 1))
            failed_names+=("$name")
            printf 'FAIL %s  (compile)\n' "$name"
            [[ $VERBOSE -eq 1 ]] && sed 's/^/      /' "$stderr_log"
            continue
        }
    else
        "$MANTICORE" compile $BACKEND_ARGS "$src" -o "$bin" > "$stderr_log" 2>&1 || {
            failed=$((failed + 1))
            failed_names+=("$name")
            printf 'FAIL %s  (compile)\n' "$name"
            [[ $VERBOSE -eq 1 ]] && sed 's/^/      /' "$stderr_log"
            continue
        }
    fi

    # Belt-and-braces: manticore can return 0 yet leave $bin missing.
    # Catch that here so the failure surfaces as `(no binary)` rather
    # than a misleading runtime error from a stale build.
    if [[ ! -x "$bin" ]]; then
        failed=$((failed + 1))
        failed_names+=("$name")
        printf 'FAIL %s  (no binary produced)\n' "$name"
        [[ $VERBOSE -eq 1 ]] && sed 's/^/      /' "$stderr_log"
        continue
    fi

    # Run.
    set +e
    "$bin" > "$actual" 2>>"$stderr_log"
    rc=$?
    set -e
    if [[ $rc -ne 0 ]]; then
        failed=$((failed + 1))
        failed_names+=("$name")
        printf 'FAIL %s  (runtime rc=%d)\n' "$name" "$rc"
        [[ $VERBOSE -eq 1 ]] && sed 's/^/      /' "$stderr_log"
        continue
    fi

    # Compare. PHP `echo "x\n"` and bash `"x"` (no \n in
    # double-quotes) both contribute textually identical lines once
    # bash's `$()` strips the trailing newline — so the legacy
    # roundtrip cases captured expected output without the trailing
    # `\n`. Normalise both sides by trimming exactly one trailing
    # newline so cases authored under either convention pass.
    expected_norm="$WORK/$name.expected.norm"
    actual_norm="$WORK/$name.actual.norm"
    awk 'NR==1{prev=$0; next} {print prev; prev=$0} END{printf "%s", prev}' "$expected" > "$expected_norm" 2>/dev/null \
        || cp "$expected" "$expected_norm"
    awk 'NR==1{prev=$0; next} {print prev; prev=$0} END{printf "%s", prev}' "$actual" > "$actual_norm" 2>/dev/null \
        || cp "$actual" "$actual_norm"
    if diff -q "$expected_norm" "$actual_norm" > /dev/null 2>&1; then
        passed=$((passed + 1))
        printf 'PASS %s\n' "$name"
    else
        failed=$((failed + 1))
        failed_names+=("$name")
        printf 'FAIL %s  (output mismatch)\n' "$name"
        if [[ $VERBOSE -eq 1 ]]; then
            echo "      ---- expected ----"
            sed 's/^/      /' "$expected"
            echo "      ---- actual ----"
            sed 's/^/      /' "$actual"
        else
            # Quick one-line diff hint. `|| true` keeps a SIGPIPE
            # from head from tripping `set -e` and ending the run.
            (diff "$expected_norm" "$actual_norm" || true) | head -4 | sed 's/^/      /' || true
        fi
    fi
    # Always drop the binary so the next run starts clean. A silent
    # compile failure that re-uses a stale binary used to mask
    # regressions — see the rm -f above the compile step.
    rm -f "$bin"
done

echo "---"
printf 'passed: %d  failed: %d  total: %d\n' "$passed" "$failed" "${#cases[@]}"
if [[ $failed -gt 0 ]]; then
    printf 'failures: %s\n' "${failed_names[*]}"
    exit 1
fi

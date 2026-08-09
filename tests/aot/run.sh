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
#   tests/aot/run.sh -j 8           # 8 cases at a time (0 = one per core)
#
# Case shapes:
#   - cases/<name>.php              → single-file compile
#   - cases/<name>/                 → directory; recursive *.php scan
#                                     (multi-file compile, entry sorts last)
#
# A case whose source carries a `@serial` marker never shares the machine with
# another case — see PARALLELISM below.

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
JOBS="${MC_JOBS:-1}"
ONE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose) VERBOSE=1; shift ;;
        -m|--mir)     BACKEND_ARGS="--backend=mir"; shift ;;
        -k|--filter)  FILTER="$2"; shift 2 ;;
        -j|--jobs)    JOBS="$2"; shift 2 ;;
        -j*)          JOBS="${1#-j}"; shift ;;
        --one)        ONE="$2"; shift 2 ;;
        -h|--help)
            sed -n '2,24p' "$0"
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

# Per-case time limits. Budgets are deliberately generous: every case is
# sub-second, but macOS XProtect spends ~670 ms scanning each freshly built binary.
# {@see tools/lib/limit.sh} for why `timeout(1)` cannot be assumed.
# shellcheck source=../../tools/lib/limit.sh
source "$ROOT/tools/lib/limit.sh"
RUN_TIMEOUT="${MC_RUN_TIMEOUT:-60}"
COMPILE_TIMEOUT="${MC_COMPILE_TIMEOUT:-300}"

# Platform-divergent expectations. A few cases CANNOT have one expected output on
# every host — `iopoll_kqueue` builds a Kqueue backend that does not exist on Linux,
# and a cmsg header is 16 bytes on Darwin vs 24 on Linux (both matching php). Rather
# than pretend otherwise (which made the Linux gate report them as regressions), an
# optional per-OS file WINS over the shared one:
#
#   expected/<name>.out           shared
#   expected/<name>.darwin.out    used on Darwin, if present
#   expected/<name>.linux.out     used on Linux, if present
OSTAG="$(uname -s | tr '[:upper:]' '[:lower:]')"

# ── one case ─────────────────────────────────────────────────────────────────
#
# Writes its verdict to stdout as ONE line (`PASS <name>` / `FAIL <name> (…)` /
# `SKIP …`) followed by any indented detail. Returns 0 pass, 1 fail, 2 SKIP —
# a skip counts as neither, so it needs its own code rather than riding on 0.
# Everything it touches under $WORK is keyed by the case name, so two cases
# never share a path — that isolation is what makes the pool below safe.
run_one() {
    local name="$1"
    local expected="$EXPECTED/$name.out"
    if [[ -f "$EXPECTED/$name.$OSTAG.out" ]]; then
        expected="$EXPECTED/$name.$OSTAG.out"
    fi
    local bin="$WORK/$name.bin"
    local stderr_log="$WORK/$name.stderr"
    local actual="$WORK/$name.out"

    if [[ ! -f "$expected" ]]; then
        echo "SKIP $name (no expected output)"
        return 2
    fi

    # Wipe any stale binary from a previous run so a silently-failing
    # compile (manticore returns 0 but produces nothing) doesn't fall
    # through to running the previous build — that masked a real
    # regression in the past.
    rm -f "$bin"

    # Compile: single-file vs directory (recursive *.php scan).
    local src="$CASES/$name.php"
    if [[ -d "$CASES/$name" ]]; then
        src="$CASES/$name"
    fi
    local crc=0
    mc_limit "$COMPILE_TIMEOUT" \
        "$MANTICORE" compile $BACKEND_ARGS "$src" -o "$bin" > "$stderr_log" 2>&1 || crc=$?
    if [[ $crc -ne 0 ]]; then
        if [[ $crc -eq 124 ]]; then
            printf 'FAIL %s  (compile TIMEOUT >%ss)\n' "$name" "$COMPILE_TIMEOUT"
        else
            printf 'FAIL %s  (compile)\n' "$name"
        fi
        [[ $VERBOSE -eq 1 ]] && sed 's/^/      /' "$stderr_log"
        return 1
    fi

    # Belt-and-braces: manticore can return 0 yet leave $bin missing.
    # Catch that here so the failure surfaces as `(no binary)` rather
    # than a misleading runtime error from a stale build.
    if [[ ! -x "$bin" ]]; then
        printf 'FAIL %s  (no binary produced)\n' "$name"
        [[ $VERBOSE -eq 1 ]] && sed 's/^/      /' "$stderr_log"
        return 1
    fi

    # Run.
    local rc=0
    set +e
    mc_limit "$RUN_TIMEOUT" "$bin" > "$actual" 2>>"$stderr_log"
    rc=$?
    set -e
    if [[ $rc -ne 0 ]]; then
        # A hang is its own outcome, not `rc=124`: a case that legitimately exits
        # non-zero is already reported that way, and the two must never be
        # confusable — one is a wrong exit status, the other is a liveness bug.
        if [[ $rc -eq 124 ]]; then
            printf 'FAIL %s  (TIMEOUT >%ss)\n' "$name" "$RUN_TIMEOUT"
        else
            printf 'FAIL %s  (runtime rc=%d)\n' "$name" "$rc"
        fi
        [[ $VERBOSE -eq 1 ]] && sed 's/^/      /' "$stderr_log"
        return 1
    fi

    # Compare. PHP `echo "x\n"` and bash `"x"` (no \n in
    # double-quotes) both contribute textually identical lines once
    # bash's `$()` strips the trailing newline — so the legacy
    # roundtrip cases captured expected output without the trailing
    # `\n`. Normalise both sides by trimming exactly one trailing
    # newline so cases authored under either convention pass.
    local expected_norm="$WORK/$name.expected.norm"
    local actual_norm="$WORK/$name.actual.norm"
    awk 'NR==1{prev=$0; next} {print prev; prev=$0} END{printf "%s", prev}' "$expected" > "$expected_norm" 2>/dev/null \
        || cp "$expected" "$expected_norm"
    awk 'NR==1{prev=$0; next} {print prev; prev=$0} END{printf "%s", prev}' "$actual" > "$actual_norm" 2>/dev/null \
        || cp "$actual" "$actual_norm"
    # Optional `cases/<name>.sed` sidecar: applied to BOTH sides before the
    # diff. Diagnostics (`Deprecated:` / `Warning:`) carry the absolute source
    # path the compiler was handed, which differs per checkout — the sidecar
    # normalises it away so the same `expected/` file works everywhere.
    if [[ -f "$CASES/$name.sed" ]]; then
        sed -f "$CASES/$name.sed" "$expected_norm" > "$expected_norm.s" && mv "$expected_norm.s" "$expected_norm"
        sed -f "$CASES/$name.sed" "$actual_norm"   > "$actual_norm.s"   && mv "$actual_norm.s"   "$actual_norm"
    fi
    if diff -q "$expected_norm" "$actual_norm" > /dev/null 2>&1; then
        printf 'PASS %s\n' "$name"
        # Always drop the binary so the next run starts clean. A silent
        # compile failure that re-uses a stale binary used to mask
        # regressions — see the rm -f above the compile step.
        rm -f "$bin"
        return 0
    fi
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
    rm -f "$bin"
    return 1
}

# The worker entry point: `run.sh --one <name>` is what the pool re-execs. The
# verdict goes to a per-case file rather than the pipe, so interleaved workers
# cannot shred each other's multi-line output; the parent replays the files in
# discovery order afterwards. Always exits 0 — the verdict file carries the
# result, and a non-zero worker would abort xargs mid-run.
if [[ -n "$ONE" ]]; then
    rc=0
    run_one "$ONE" > "$WORK/$ONE.verdict" 2>&1 || rc=$?
    printf '%d\n' "$rc" > "$WORK/$ONE.rc"
    # Live progress on STDERR. stdout is the ordered, diffable log and must not
    # carry interleaved lines; stderr is where a human watching a five-minute
    # run needs to see that it is moving. One short line per case is a single
    # write, under PIPE_BUF, so workers cannot shred each other's.
    head -1 "$WORK/$ONE.verdict" >&2
    exit 0
fi

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

# ── PARALLELISM ──────────────────────────────────────────────────────────────
#
# Every case is independent — its own compile, its own binary, its own $WORK
# paths — so the suite is embarrassingly parallel, and it is worth doing: the
# wall clock is almost entirely single-threaded `clang` invocations, one per
# case, and there are ~1000 of them.
#
# `xargs -P` rather than `wait -n`: macOS ships bash 3.2, which has neither
# `wait -n` nor associative arrays, and this script must run on the dev host and
# in the Linux gate container alike.
#
# ⚠ NOT everything can share the machine. A case that binds a FIXED PORT or
# forks a server collides with itself when a neighbour does the same, and the
# failure looks like a compiler regression rather than a harness artefact. Such
# a case declares `@serial` in its source and is run alone, after the pool
# drains. The marker lives in the case FILE on purpose — an out-of-line list
# drifts from the cases it names.
#
# Default stays 1. The suite is a GATE: its verdicts are compared across hosts
# and commits, so the reproducible ordering is the default and speed is opt-in.
if [[ "$JOBS" == "0" ]]; then
    if command -v sysctl >/dev/null 2>&1 && sysctl -n hw.ncpu >/dev/null 2>&1; then
        JOBS="$(sysctl -n hw.ncpu)"
    elif command -v nproc >/dev/null 2>&1; then
        JOBS="$(nproc)"
    else
        JOBS=4
    fi
fi
if ! [[ "$JOBS" =~ ^[0-9]+$ ]] || [[ "$JOBS" -lt 1 ]]; then
    echo "fatal: -j takes a non-negative integer (0 = one job per core)" >&2
    exit 1
fi

passed=0
failed=0
failed_names=()

if [[ "$JOBS" -gt 1 ]]; then
    parallel=()
    serial=()
    for name in "${cases[@]}"; do
        src="$CASES/$name.php"
        [[ -d "$CASES/$name" ]] && src="$CASES/$name"
        if grep -rqs '@serial' "$src"; then
            serial+=("$name")
        else
            parallel+=("$name")
        fi
    done
    printf 'running %d case(s) %d at a time, %d serial\n' \
        "${#parallel[@]}" "$JOBS" "${#serial[@]}"
    rm -f "$WORK"/*.verdict "$WORK"/*.rc 2>/dev/null || true
    VOPT=""
    [[ $VERBOSE -eq 1 ]] && VOPT="-v"
    if [[ ${#parallel[@]} -gt 0 ]]; then
        # shellcheck disable=SC2086  # VOPT is one optional flag, split on purpose
        printf '%s\n' "${parallel[@]}" \
            | xargs -P "$JOBS" -n 1 "$0" $VOPT --one
    fi
    # Guarded: bash 3.2 (what macOS ships) treats `"${empty[@]}"` as an unbound
    # variable under `set -u`, so an all-parallel run would die right here.
    if [[ ${#serial[@]} -gt 0 ]]; then
        for name in "${serial[@]}"; do
            # shellcheck disable=SC2086
            "$0" $VOPT --one "$name"
        done
    fi
    # Replay in DISCOVERY order: a parallel run's log must diff cleanly against
    # a serial one, or the two cannot be compared when a verdict moves.
    for name in "${cases[@]}"; do
        if [[ -f "$WORK/$name.verdict" ]]; then
            cat "$WORK/$name.verdict"
        else
            printf 'FAIL %s  (no verdict — worker died)\n' "$name"
            failed=$((failed + 1))
            failed_names+=("$name")
            continue
        fi
        rc="$(cat "$WORK/$name.rc" 2>/dev/null || echo 1)"
        if [[ "$rc" == "0" ]]; then
            passed=$((passed + 1))
        elif [[ "$rc" == "2" ]]; then
            :                       # SKIP: neither passed nor failed
        else
            failed=$((failed + 1))
            failed_names+=("$name")
        fi
    done
else
    for name in "${cases[@]}"; do
        rc=0
        run_one "$name" || rc=$?
        if [[ $rc -eq 0 ]]; then
            passed=$((passed + 1))
        elif [[ $rc -eq 2 ]]; then
            :
        else
            failed=$((failed + 1))
            failed_names+=("$name")
        fi
    done
fi

echo "---"
printf 'passed: %d  failed: %d  total: %d\n' "$passed" "$failed" "${#cases[@]}"
if [[ $failed -gt 0 ]]; then
    printf 'failures: %s\n' "${failed_names[*]}"
    exit 1
fi

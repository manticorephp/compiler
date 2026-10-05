#!/usr/bin/env bash
#
# Known-bug runner: the INVERSE of run.sh.
#
# Every tests/aot/repro/**/<name>.php with a php oracle output beside it
# (<name>.expected) is a reproducer of an OPEN bug, so it is EXPECTED to fail:
#
#   XFAIL  still broken — the bug is tracked, nothing to do
#   XPASS  it passes now — the bug is fixed (maybe as a side effect). PROMOTE it:
#          git mv the .php into tests/aot/cases/ and the .expected into
#          tests/aot/expected/<name>.out, and close the issue
#   SKIP   no .expected beside it (a superset repro with no Zend oracle)
#
# Exit 0 while every repro is still red, 1 on any XPASS — a tracker that says
# "open" about a fixed bug is the thing this exists to catch. Not part of
# run.sh: the suite must stay green while these are open.
#
# A repro names its issue in a comment: `// issue: #123`.
#
# Usage:
#   tests/aot/xfail.sh              # all repros
#   tests/aot/xfail.sh -k await     # filter substring (matches the path too)
#   tests/aot/xfail.sh -v           # show stderr / the diff of every XFAIL
#   tests/aot/xfail.sh -j 8         # 8 at a time (0 = one per core)

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MANTICORE="$ROOT/bin/manticore"
REPRO="tests/aot/repro"
WORK="$ROOT/tests/aot/.work/xfail"

VERBOSE=0
FILTER=""
JOBS="${MC_JOBS:-1}"
ONE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose) VERBOSE=1; shift ;;
        -k|--filter)  FILTER="$2"; shift 2 ;;
        -j|--jobs)    JOBS="$2"; shift 2 ;;
        -j*)          JOBS="${1#-j}"; shift ;;
        --one)        ONE="$2"; shift 2 ;;
        -h|--help)    sed -n '2,25p' "$0"; exit 0 ;;
        *) FILTER="$1"; shift ;;
    esac
done

if [[ ! -x "$MANTICORE" ]]; then
    echo "fatal: $MANTICORE not built; run bin/build first" >&2
    exit 2
fi

mkdir -p "$WORK"

# shellcheck source=../../tools/lib/limit.sh
source "$ROOT/tools/lib/limit.sh"
# Shorter than run.sh's 60 s: a hang IS a common shape of an open bug here, and
# every one of them costs the whole budget on every run.
RUN_TIMEOUT="${MC_RUN_TIMEOUT:-20}"
COMPILE_TIMEOUT="${MC_COMPILE_TIMEOUT:-300}"

# Returns 0 XFAIL, 1 XPASS, 2 SKIP. $1 is the source path relative to the repo.
run_one() {
    local src="$1"
    local rel="${src#"$REPRO"/}"
    rel="${rel%.php}"
    local expected="${src%.php}.expected"
    local key="${rel//\//__}"
    local bin="$WORK/$key.bin"
    local log="$WORK/$key.stderr"
    local actual="$WORK/$key.out"
    local issue
    issue="$(grep -m1 -oE 'issue: *#[0-9]+' "$src" 2>/dev/null | grep -oE '#[0-9]+' || true)"
    [[ -n "$issue" ]] || issue="no issue"

    if [[ ! -f "$expected" ]]; then
        printf 'SKIP  %s  [%s]  (no .expected)\n' "$rel" "$issue"
        return 2
    fi

    rm -f "$bin"
    local why="" crc=0 rc=0
    mc_limit "$COMPILE_TIMEOUT" "$MANTICORE" compile "$src" -o "$bin" > "$log" 2>&1 || crc=$?
    if [[ $crc -eq 124 ]]; then
        why="compile TIMEOUT >${COMPILE_TIMEOUT}s"
    elif [[ $crc -ne 0 ]]; then
        why="compile"
    elif [[ ! -x "$bin" ]]; then
        why="no binary produced"
    else
        mc_limit "$RUN_TIMEOUT" "$bin" > "$actual" 2>>"$log" || rc=$?
        if [[ $rc -eq 124 ]]; then
            why="TIMEOUT >${RUN_TIMEOUT}s"
        elif [[ $rc -ne 0 ]]; then
            why="runtime rc=$rc"
        elif ! cmp -s "$expected" "$actual"; then
            why="output mismatch"
        fi
    fi

    if [[ -z "$why" ]]; then
        printf 'XPASS %s  [%s]  — fixed: promote to cases/ + expected/, close the issue\n' "$rel" "$issue"
        rm -f "$bin" "$log" "$actual"
        return 1
    fi
    printf 'XFAIL %s  [%s]  (%s)\n' "$rel" "$issue" "$why"
    if [[ $VERBOSE -eq 1 ]]; then
        if [[ "$why" == "output mismatch" ]]; then
            (diff "$expected" "$actual" || true) | head -12 | sed 's/^/      /' || true
            # A leak repro reports how far the RSS grew on stderr.
            tail -3 "$log" | sed 's/^/      /' || true
        else
            head -12 "$log" | sed 's/^/      /' || true
        fi
    fi
    rm -f "$bin" "$log" "$actual"
    return 0
}

# Worker entry point, same shape as run.sh: the verdict goes to a per-case file
# so interleaved workers cannot shred each other's output.
if [[ -n "$ONE" ]]; then
    key="${ONE#"$REPRO"/}"; key="${key%.php}"; key="${key//\//__}"
    rc=0
    run_one "$ONE" > "$WORK/$key.verdict" 2>&1 || rc=$?
    printf '%d\n' "$rc" > "$WORK/$key.rc"
    head -1 "$WORK/$key.verdict" >&2
    exit 0
fi

srcs=()
while IFS= read -r f; do
    [[ -z "$FILTER" || "$f" == *"$FILTER"* ]] && srcs+=("$f")
done < <(find "$REPRO" -name '*.php' -type f | LC_ALL=C sort)

if [[ ${#srcs[@]} -eq 0 ]]; then
    echo "xfail: no repros${FILTER:+ match '$FILTER'} — nothing open"
    exit 0
fi

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
    exit 2
fi

xfail=0; xpass=0; skip=0
xpass_names=()

tally() {
    case "$1" in
        0) xfail=$((xfail + 1)) ;;
        2) skip=$((skip + 1)) ;;
        *) xpass=$((xpass + 1)); xpass_names+=("$2") ;;
    esac
}

if [[ "$JOBS" -gt 1 ]]; then
    rm -f "$WORK"/*.verdict "$WORK"/*.rc 2>/dev/null || true
    VOPT=""
    [[ $VERBOSE -eq 1 ]] && VOPT="-v"
    # shellcheck disable=SC2086  # VOPT is one optional flag, split on purpose
    printf '%s\n' "${srcs[@]}" | xargs -P "$JOBS" -n 1 "$0" $VOPT --one
    for src in "${srcs[@]}"; do
        key="${src#"$REPRO"/}"; key="${key%.php}"; key="${key//\//__}"
        if [[ -f "$WORK/$key.verdict" ]]; then
            cat "$WORK/$key.verdict"
            tally "$(cat "$WORK/$key.rc" 2>/dev/null || echo 0)" "$src"
        else
            # A dead worker is not evidence of a fix: count it as still broken.
            printf 'XFAIL %s  (no verdict — worker died)\n' "$key"
            tally 0 "$key"
        fi
        rm -f "$WORK/$key.verdict" "$WORK/$key.rc"
    done
else
    for src in "${srcs[@]}"; do
        rc=0
        run_one "$src" || rc=$?
        tally "$rc" "$src"
    done
fi

echo "---"
printf 'xfail: %d  xpass: %d  skip: %d  total: %d\n' "$xfail" "$xpass" "$skip" "${#srcs[@]}"
if [[ $xpass -gt 0 ]]; then
    printf 'fixed, promote: %s\n' "${xpass_names[*]}"
    exit 1
fi

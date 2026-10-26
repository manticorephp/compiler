#!/usr/bin/env bash
#
# Strict-mode memory/time vs a base compiler (median of N runs, peak RSS MB / wall s).
#
# Usage: tests/parser/mem_check.sh --base PATH_TO_BASE_COMPILER [-n RUNS]
# The tree's compiler is bin/manticore. Targets: delta RSS within 1%.
# macOS only: it reads BSD `/usr/bin/time -l` (bytes) output.

set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BASE=""; N=3
while [[ $# -gt 0 ]]; do
    case "$1" in
        --base) BASE="$2"; shift 2 ;;
        -n) N="$2"; shift 2 ;;
        *) echo "usage: $0 --base COMPILER [-n RUNS]" >&2; exit 2 ;;
    esac
done
[[ -x "$BASE" ]] || { echo "need --base COMPILER" >&2; exit 2; }
TREE="$ROOT/bin/manticore"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

# run COMPILER ARGS... -> "rssMB wall" of one run
one() {
    local c="$1"; shift
    /usr/bin/time -l "$c" "$@" >/dev/null 2>"$W/t" || { echo "run failed: $c $*" >&2; echo "nan nan"; return; }
    awk '/maximum resident/ {r=$1/1048576} /real/ {w=$1} END {printf "%.2f %.2f\n", r, w}' "$W/t"
}
median() { sort -n | awk '{a[NR]=$1} END {print a[int((NR+1)/2)]}'; }
measure() {
    local c="$1"; shift
    local i rss=() wall=()
    for ((i = 0; i < N; i++)); do
        read -r r w < <(one "$c" "$@")
        rss+=("$r"); wall+=("$w")
    done
    printf '%s %s\n' "$(printf '%s\n' "${rss[@]}" | median)" "$(printf '%s\n' "${wall[@]}" | median)"
}

printf '%-28s %16s %16s %8s\n' job base tree 'd-rss'
row() {
    local name="$1"; shift
    local b t
    b=$(measure "$BASE" "$@"); t=$(measure "$TREE" "$@")
    awk -v n="$name" -v b="$b" -v t="$t" 'BEGIN {
        split(b, x, " "); split(t, y, " ");
        printf "%-28s %6.1f / %5.2fs %6.1f / %5.2fs %+7.1f%%\n", n, x[1], x[2], y[1], y[2], (y[1]/x[1]-1)*100 }'
}
C="$ROOT/tests/aot/cases"
row compile-hello_world compile "$C/hello_world.php" -o "$W/o1"
row dump-ast-EmitLlvmBuiltins dump-ast "$ROOT/src/Compile/Mir/Passes/EmitLlvmBuiltins.php"
row compile-openssl_cms compile "$C/openssl_cms.php" -o "$W/o2"

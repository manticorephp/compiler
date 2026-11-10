#!/usr/bin/env bash
#
# Shared generics through the object cache: a large prelude body is compiled once
# per variant and reused by every later program that emits the same text.
#
#   bash tests/generic/cache.sh
#
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
MANT="$ROOT/bin/manticore"
[[ -x "$MANT" ]] || { echo "no bin/manticore — run bin/build first" >&2; exit 2; }

WORK="$ROOT/tests/generic/.cache-work"
rm -rf "$WORK"
mkdir -p "$WORK"
trap 'chmod -R u+w "$WORK" 2>/dev/null; rm -rf "$WORK"' EXIT
export MANTICORE_OBJ_CACHE_DIR="$WORK/cache"
fail=0

# compile <case> <tag> [env...]: builds, checks the php oracle, leaves the trace in $WORK/<tag>.trace
compile() {
    local name="$1" tag="$2"; shift 2
    if ! env "$@" MANTICORE_PHASE_TRACE=1 "$MANT" compile -O2 "tests/generic/cases/$name.php" -o "$WORK/$tag" \
        > "$WORK/$tag.trace" 2>&1; then
        echo "FAIL $tag: compile"; tail -5 "$WORK/$tag.trace"; fail=1; return 1
    fi
    if ! "$WORK/$tag" > "$WORK/$tag.out" 2>&1 || ! cmp -s "$WORK/$tag.out" "tests/generic/cases/$name.out"; then
        echo "FAIL $tag: output differs from php"; fail=1; return 1
    fi
}
# hits <tag>: "H N" from the trace's `obj cache: H/N parts hit`
hits() { sed -nE 's/.*obj cache: ([0-9]+)\/([0-9]+) parts hit.*/\1 \2/p' "$WORK/$1.trace" | tail -1; }
expect() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi; }

compile arrays_sort cold && read -r h n <<< "$(hits cold)" \
    && expect "cold cache: $n parts, $h hits" '[[ ${n:-0} -ge 2 && ${h:-1} -eq 0 ]]'
expect "program part kept apart in prog/, bodies at the top" \
    '[[ $(ls "$MANTICORE_OBJ_CACHE_DIR"/prog/*.o 2>/dev/null | wc -l) -eq 1 && $(ls "$MANTICORE_OBJ_CACHE_DIR"/*.o 2>/dev/null | wc -l) -ge 1 ]]'
compile arrays_sort warm && read -r h n <<< "$(hits warm)" \
    && expect "same program again: $h/$n parts from the cache" '[[ ${n:-0} -ge 2 && ${h:-0} -eq ${n:-1} ]]'
compile closures other && read -r h n <<< "$(hits other)" \
    && expect "another program reuses shared bodies: $h/$n" '[[ ${h:-0} -ge 1 ]]'

rm -rf "$MANTICORE_OBJ_CACHE_DIR"
compile json_strings par1 & p1=$!
compile string_callable par2 & p2=$!
wait $p1; r1=$?; wait $p2; r2=$?
expect "two compiles racing on one cache" '[[ $r1 -eq 0 && $r2 -eq 0 ]]'

chmod -R a-w "$MANTICORE_OBJ_CACHE_DIR"
compile exceptions ro && expect "read-only cache still builds" 'true'
chmod -R u+w "$MANTICORE_OBJ_CACHE_DIR"

compile reflection off MANTICORE_SHARED_GENERICS=0 \
    && expect "MANTICORE_SHARED_GENERICS=0 compiles one unit" '! grep -q "shared split" "$WORK/off.trace"'
compile arrayobject_refname split MANTICORE_SPLIT_JOBS=4 && expect "MANTICORE_SPLIT_JOBS=4" 'true'

[[ $fail -eq 0 ]] && echo "ok: shared generics cache"
exit $fail

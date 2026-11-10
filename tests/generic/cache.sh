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

# The same program twice at once on an empty cache: both writers put the SAME
# keys at the same moment; neither may leave a torn object for the other.
for round in 1 2 3; do
    rm -rf "$MANTICORE_OBJ_CACHE_DIR"
    compile arrays_sort same1 & q1=$!
    compile arrays_sort same2 & q2=$!
    wait $q1; s1=$?; wait $q2; s2=$?
    compile arrays_sort same3 && read -r h n <<< "$(hits same3)"
    expect "round $round: one key, two writers, then $h/$n hits" '[[ $s1 -eq 0 && $s2 -eq 0 && ${h:-0} -eq ${n:-1} ]]'
    [[ -z "$(ls "$MANTICORE_OBJ_CACHE_DIR"/*.tmp* "$MANTICORE_OBJ_CACHE_DIR"/prog/*.tmp* 2>/dev/null)" ]] \
        || { echo "FAIL round $round: temp files left in the cache"; fail=1; }
done

chmod -R a-w "$MANTICORE_OBJ_CACHE_DIR"
compile exceptions ro && expect "read-only cache still builds" 'true'
chmod -R u+w "$MANTICORE_OBJ_CACHE_DIR"

compile reflection off MANTICORE_SHARED_GENERICS=0 \
    && expect "MANTICORE_SHARED_GENERICS=0 compiles one unit" '! grep -q "shared split" "$WORK/off.trace"'
compile arrayobject_refname split MANTICORE_SPLIT_JOBS=4 && expect "MANTICORE_SPLIT_JOBS=4" 'true'

# `build` stages its module; the shared parts apply there too.
app="$WORK/app"; mkdir -p "$app/src"
cp tests/generic/cases/arrays_sort.php "$app/src/main.php"
printf '{"applications":[{"name":"a","src":"src","entry":"src/main.php","output":"bin/a"}]}\n' > "$app/manticore.json"
build_app() {
    (cd "$app" && MANTICORE_PHASE_TRACE=1 "$MANT" build > "$WORK/$1.trace" 2>&1) \
        && "$app/bin/a" > "$WORK/$1.out" 2>&1 && cmp -s "$WORK/$1.out" tests/generic/cases/arrays_sort.out
}
if build_app build1 && build_app build2; then
    read -r h n <<< "$(hits build2)"
    expect "build: second build serves $h/$n parts from the cache" '[[ ${n:-0} -ge 2 && ${h:-0} -eq ${n:-1} ]]'
else
    echo "FAIL build: build or output"; tail -5 "$WORK/build1.trace"; fail=1
fi

# Cached link flags that no longer link (a host change the key does not see):
# the compile forgets them, probes again and links.
linkdir="$(dirname "$MANTICORE_OBJ_CACHE_DIR")/link"
if ls "$linkdir"/*.txt >/dev/null 2>&1; then
    for lf in "$linkdir"/*.txt; do printf ' -lmanticore_no_such_library' > "$lf"; done
    compile arrays_sort relink && expect "stale cached link flags: probed again and linked" '! grep -q manticore_no_such_library "$linkdir"/*.txt'
else
    echo "FAIL link flags were not cached"; fail=1
fi

[[ $fail -eq 0 ]] && echo "ok: shared generics cache"
exit $fail

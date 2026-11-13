#!/usr/bin/env bash
#
# The stdlib bootstrap twins (a runtime: true library) compiled into a stdlib:false program: builtins still
# shadow them. --apps-only: the twins library alone does not build (it calls the stdlib it stands in for).
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1
MC="$ROOT/bin/manticore"
FIX="$ROOT/tests/libs/builtin_twins"
WORK="$FIX/.work"

if [ ! -x "$MC" ]; then
    echo "libbuiltintwins: no bin/manticore — run bin/build first"
    exit 1
fi

rm -rf "$WORK"
mkdir -p "$WORK"
if ! "$MC" build --apps-only "$FIX/manticore.json" > "$WORK/build.log" 2>&1; then
    echo "FAIL build"
    cat "$WORK/build.log"
    exit 1
fi
"$WORK/twins" > "$WORK/got.out" 2>&1
rc=$?
fails=0
if [ "$rc" -ne 0 ]; then echo "FAIL twins exited rc=$rc"; fails=1; fi
if ! diff -u "$FIX/expected.out" "$WORK/got.out"; then echo "FAIL output differs from php"; fails=1; fi
rm -rf "$WORK"
if [ "$fails" -eq 0 ]; then echo "=== RESULT: libbuiltintwins OK"; exit 0; fi
echo "=== RESULT: libbuiltintwins FAIL"
exit 1

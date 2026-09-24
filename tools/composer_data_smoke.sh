#!/usr/bin/env bash
#
# Composer compile-unit selection: a pure DATA file under a psr-4 root
# (`return array (...)` — symfony/polyfill-mbstring's Resources/unidata) must be
# a compile unit its runtime `require` reads, while a script under the same root
# is still dropped and never runs.
#
# Not part of tests/aot/run.sh — that runner has no composer manifest mode.
# Modelled on tools/libclass_smoke.sh.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MC="$ROOT/bin/manticore"
FIX="$ROOT/tests/libs/composer_data"
WORK="$FIX/.work"

if [ ! -x "$MC" ]; then
    echo "composer_data: no bin/manticore — run bin/build first"
    exit 1
fi

rm -rf "$WORK"
mkdir -p "$WORK"
cd "$FIX" || exit 1
if ! "$MC" build manticore.json > "$WORK/build.log" 2>&1; then
    echo "FAIL build"
    tail -20 "$WORK/build.log"
    exit 1
fi
"$WORK/dataapp" > "$WORK/got.out" 2>&1
rc=$?
if [ "$rc" -ne 0 ]; then echo "FAIL dataapp exited rc=$rc"; exit 1; fi
if ! diff -u "$FIX/expected.out" "$WORK/got.out"; then
    echo "=== RESULT: composer_data FAIL (output differs from php)"
    exit 1
fi
echo "=== RESULT: composer_data OK"

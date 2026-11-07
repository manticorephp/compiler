#!/usr/bin/env bash
#
# Lazy compile units: a src file's top level runs at its require, not at startup. Byte parity against the php interpreter.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1
MC="$ROOT/bin/manticore"
FIX="$ROOT/tests/libs/lazy_units"
WORK="$FIX/.work"

if [ ! -x "$MC" ]; then
    echo "liblazyunits: no bin/manticore — run bin/build first"
    exit 1
fi

rm -rf "$WORK"
mkdir -p "$WORK"
if ! "$MC" build "$FIX/manticore.json" > "$WORK/build.log" 2>&1; then
    echo "FAIL build"
    cat "$WORK/build.log"
    exit 1
fi
"$WORK/lazyunits" > "$WORK/got.out" 2>&1
rc=$?
fails=0
if [ "$rc" -ne 0 ]; then echo "FAIL lazyunits exited rc=$rc"; fails=1; fi
if ! diff -u "$FIX/expected.out" "$WORK/got.out"; then echo "FAIL output differs from php"; fails=1; fi
rm -rf "$WORK"
if [ "$fails" -eq 0 ]; then echo "=== RESULT: liblazyunits OK"; exit 0; fi
echo "=== RESULT: liblazyunits FAIL"
exit 1

#!/usr/bin/env bash
#
# Cross-module BY-REF VARIADIC gate: a library declares `&...$xs` and the
# application calls it knowing only the interface `.sig`, so the `.sig` must
# say the pack holds references or the caller packs values and every write is
# lost. Byte parity against the php interpreter.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1
MC="$ROOT/bin/manticore"
FIX="$ROOT/tests/libs/refpack"
WORK="$FIX/.work"

if [ ! -x "$MC" ]; then
    echo "librefpack: no bin/manticore — run bin/build first"
    exit 1
fi

rm -rf "$WORK"
mkdir -p "$WORK"
if ! "$MC" build "$FIX/manticore.json" > "$WORK/build.log" 2>&1; then
    echo "FAIL build"
    cat "$WORK/build.log"
    exit 1
fi
"$WORK/refpackapp" > "$WORK/got.out" 2>&1
rc=$?
fails=0
if [ "$rc" -ne 0 ]; then echo "FAIL refpackapp exited rc=$rc"; fails=1; fi
if ! diff -u "$FIX/expected.out" "$WORK/got.out"; then echo "FAIL output differs from php"; fails=1; fi
rm -rf "$WORK"
if [ "$fails" -eq 0 ]; then echo "=== RESULT: librefpack OK"; exit 0; fi
echo "=== RESULT: librefpack FAIL"
exit 1

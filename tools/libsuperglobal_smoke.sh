#!/usr/bin/env bash
#
# Cross-module SUPERGLOBAL gate: a library and the application that links it
# both read and overwrite `$_GET`. The slot is shared (the library declares it,
# the application defines it), so its representation must be the same in both
# modules whatever each one stores — and every store must release what it
# overwrites exactly once. Byte parity against the php interpreter.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1
MC="$ROOT/bin/manticore"
FIX="$ROOT/tests/libs/superglobals"
WORK="$FIX/.work"

if [ ! -x "$MC" ]; then
    echo "libsuperglobal: no bin/manticore — run bin/build first"
    exit 1
fi

rm -rf "$WORK"
mkdir -p "$WORK"
if ! "$MC" build "$FIX/manticore.json" > "$WORK/build.log" 2>&1; then
    echo "FAIL build"
    cat "$WORK/build.log"
    exit 1
fi
"$WORK/gpcapp" > "$WORK/got.out" 2>&1
rc=$?
fails=0
if [ "$rc" -ne 0 ]; then echo "FAIL gpcapp exited rc=$rc"; fails=1; fi
if ! diff -u "$FIX/expected.out" "$WORK/got.out"; then echo "FAIL output differs from php"; fails=1; fi
rm -rf "$WORK"
if [ "$fails" -eq 0 ]; then echo "=== RESULT: libsuperglobal OK"; exit 0; fi
echo "=== RESULT: libsuperglobal FAIL"
exit 1

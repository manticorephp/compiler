#!/usr/bin/env bash
# Extension-system smoke gate: build the zlib example via `manticore build`
# (compiles the extension glue into the app + links -lz) and check the
# FFI-backed crc32 output against the known reference.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

OUT="/tmp/zlibtest_bin"
EXPECTED="907060870"

rm -f "$OUT"
bin/manticore build ext/zlib_test/manticore.json >/dev/null
GOT="$("$OUT")"
if [[ "$GOT" != "$EXPECTED" ]]; then
    echo "EXT SMOKE FAIL: crc32(\"hello\") = '$GOT', expected '$EXPECTED'" >&2
    exit 1
fi
echo "EXT SMOKE OK: zlib extension links + crc32 = $EXPECTED"

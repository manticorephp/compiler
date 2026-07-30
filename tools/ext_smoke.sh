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

# The same app with `"link": []` — `-lz` can only come from the
# `#[Ffi\Library('z')]` on the binding. Until that attribute drove linking this
# build resolved crc32 through link_stubs.sh's generated `return 0` stub and
# printed 0, so check the OUTPUT, not just the exit status.
OUT2="/tmp/zlibtest_attrlink_bin"
rm -f "$OUT2"
bin/manticore build ext/zlib_test/manticore_attrlink.json >/dev/null
GOT2="$("$OUT2")"
if [[ "$GOT2" != "$EXPECTED" ]]; then
    echo "EXT SMOKE FAIL (#[Ffi\\Library] link): crc32(\"hello\") = '$GOT2', expected '$EXPECTED'" >&2
    exit 1
fi
echo "EXT SMOKE OK: #[Ffi\\Library('z')] alone links libz + crc32 = $EXPECTED"

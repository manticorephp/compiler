#!/usr/bin/env bash
#
# Self-host build: rebuild the compiler USING the compiler (not Zend).
#
#   tools/selfhost.sh [stage_n_binary] [output]
#
# The Stage-N manticore binary (default bin/manticore) compiles the whole
# of src/ to LLVM IR, which is then assembled + linked into the Stage-(N+1)
# binary. Mirrors bin/compile's clang/stub/link tail, but the front-end is
# the native compiler instead of `php tools/compile_files_mir.php`.
#
# bin/compile (Zend front-end) still bootstraps the FIRST binary; once it
# exists, this script reproduces it. `tools/selfhost_fixpoint.sh` chains two
# generations and asserts they are byte-identical.
#
# Runtime-free: undefined externals (the FFI-runtime bridge symbols) get a
# void* stub, exactly as bin/compile does.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# The stage binary lives outside the repo (e.g. /tmp/manticore_g2), so its
# argv0-relative prelude lookup can't reach repo/prelude. Point it at the
# canonical prelude dir so sort/array_reduce inject identically across
# generations — else g2 (built by the in-repo bin/manticore, which finds the
# prelude) and g3 (built by the /tmp stage binary, which can't) diverge.
export MANTICORE_PRELUDE="$ROOT/prelude"

MANTICORE="${1:-bin/manticore}"
OUT="${2:-bin/manticore_self}"
OUT_DIR="$(dirname "$OUT")"
OUT_BASE="$(basename "$OUT")"
mkdir -p "$OUT_DIR"

LL="$OUT_DIR/${OUT_BASE}.ll"
OBJ="$OUT_DIR/${OUT_BASE}.o"
STUBS_PREFIX="$OUT_DIR/${OUT_BASE}"

if [[ ! -x "$MANTICORE" ]]; then
    echo "fatal: $MANTICORE not executable; run bin/compile first" >&2
    exit 1
fi

echo "[1/4] $MANTICORE dump-llvm-mir src -> $LL"
"$MANTICORE" dump-llvm-mir src > "$LL"

echo "[2/4] assemble $LL -> $OBJ"
clang -c -x ir "$LL" -o "$OBJ" -Wno-override-module

echo "[3/4+4/4] stub undefined symbols, link -> $OUT"
STUBS_PREFIX="$STUBS_PREFIX" bash tools/link_stubs.sh "$OUT" "$OBJ"

echo "ok: $OUT ($(stat -f%z "$OUT" 2>/dev/null || stat -c%s "$OUT") bytes)"

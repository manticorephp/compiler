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

# ⚠ DO NOT default MANTICORE_MEMORY here. It was set to `arena` on the belief that
# it only tuned the compiler PROCESS — the comment said "this affects only the
# compiler process below". It does not: {@see \Manticore\parse_compile_args} feeds
# it straight into CompileArgs::$memory, i.e. the EMITTED target's memory mode,
# and `arena` means "process-wide bump pointer, refcount ops elided".
#
# Every self-host generation was therefore built with refcounting elided, and the
# resulting binary CRASHES: gen-2 segfaults on `--version`, before it parses an
# argument. Measured — array releases 2804 -> 713, IR 64.33 MB -> 63.50 MB, and
# the crash follows the BUILD mode, not the run mode (built-arena dies whether or
# not the env is set at runtime; built-plain survives either way). That is what
# broke the Linux fixpoint gate, and macOS is no different — it simply had not
# run the gate. An explicit MANTICORE_MEMORY in the environment is still honoured.
#
# The compiler's own default is HYBRID (escape analysis decides per allocation,
# {@see \Compile\Debug}). Unset and `hybrid` emit byte-identical IR — measured,
# 64 330 667 B either way — so leaving this alone IS choosing hybrid.

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

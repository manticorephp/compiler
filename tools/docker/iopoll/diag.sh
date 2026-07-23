#!/usr/bin/env bash
# Diagnose the pre-existing Linux cold-seed `free(): invalid pointer` abort.
# Replicates bin/compile stages 1-4, then runs the seed's manifest build (stage
# 5) under gdb to backtrace the invalid free. Runs INSIDE the container.
set -uo pipefail

cp -a /repo /build/src
cd /build/src || exit 2
rm -rf bin/manticore lib tests/aot/tmp
export MANTICORE_PRELUDE="$PWD/prelude"

SEED_DIR="$(mktemp -d)"
SEED="$SEED_DIR/manticore_seed"
LL="$SEED_DIR/seed.ll"
OBJ="$SEED_DIR/seed.o"

echo "[1/4] Zend bootstrap: src/ -> seed IR"
find src -name "*.php" | sort | xargs php -d memory_limit=2048M tools/compile_files_mir.php > "$LL" 2>/build/ll.err \
    || { echo "IR FAIL"; tail -20 /build/ll.err; exit 1; }

echo "[2/4] assemble seed"
clang -c "$LL" -o "$OBJ" -Wno-override-module 2>/build/asm.err \
    || { echo "ASM FAIL"; tail -20 /build/asm.err; exit 1; }

echo "[3/4] link seed"
STUBS_PREFIX="$SEED_DIR/seed" bash tools/link_stubs.sh "$SEED" "$OBJ" >/build/link.log 2>&1 \
    || { echo "LINK FAIL"; tail -20 /build/link.log; exit 1; }
mkdir -p lib/prelude && cp prelude/*.php lib/prelude/

echo "[5/5] seed build under gdb (backtrace the abort)"
gdb --batch \
    -ex 'set pagination off' \
    -ex 'run' \
    -ex 'echo \n==== BACKTRACE ====\n' \
    -ex 'bt' \
    -ex 'echo \n==== FRAME (free caller) ====\n' \
    -ex 'frame 4' \
    -ex 'info registers' \
    --args "$SEED" build manticore.json 2>&1 | tail -80

echo ""
echo "==================== VALGRIND (invalid free + block origin) ===================="
valgrind --error-exitcode=0 --num-callers=25 --track-origins=yes \
    "$SEED" build manticore.json 2>&1 | grep -A40 -i "invalid free\|invalid write\|invalid read\|mismatched" | head -70

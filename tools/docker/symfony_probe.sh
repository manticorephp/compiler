#!/usr/bin/env bash
# Build the symfony/console probe with a LINUX manticore and diff the 7 cases
# against the php oracle, inside a container.
#
#   bash probe_linux.sh linux/arm64
#   bash probe_linux.sh linux/amd64
#
# Both trees are mounted READ-ONLY and copied to scratch: the container writes
# Linux binaries, and the host checkout is macOS.
set -euo pipefail

PLATFORM="${1:-linux/arm64}"
ARCH="${PLATFORM#linux/}"
REPO=/Users/taras/var/projects/manticore-symfony
PROBE=/Users/taras/var/projects/symfony-probe
IMAGE="manticore-toolchain:$ARCH"

docker build --platform "$PLATFORM" --target toolchain -t "$IMAGE" -f "$REPO/Dockerfile" "$REPO" >&2

RUNNER='
set -u
cp -a /repo /build/src-tree && cd /build/src-tree
rm -rf bin/manticore lib/ tests/aot/tmp 2>/dev/null || true
echo "=== uname: $(uname -m) ==="
echo "=== cold seed (bin/compile) ==="
bash bin/compile > /tmp/compile.log 2>&1
rc=$?
echo "bin/compile rc=$rc"
if [ "$rc" != "0" ]; then tail -40 /tmp/compile.log; exit 1; fi

cp -a /probe /build/probe-tree && cd /build/probe-tree
rm -f app app.dbg.ll app.dbg.o
echo "=== probe build ==="
MANTICORE_PRELUDE=/build/src-tree/prelude /build/src-tree/bin/manticore build manticore.json > /tmp/probe.log 2>&1
rc=$?
echo "probe build rc=$rc"
if [ "$rc" != "0" ]; then tail -40 /tmp/probe.log; exit 1; fi
ls -la app

echo "=== oracle (php) ==="
bash run_cases.sh "php bin/app.php" > /tmp/oracle.txt 2>&1
echo "=== native ==="
bash run_cases.sh ./app > /tmp/native.txt 2>&1
if diff -u /tmp/oracle.txt /tmp/native.txt > /tmp/d.txt 2>&1; then
    echo "RESULT: BYTE-EXACT (7/7) on $(uname -m)"
else
    echo "RESULT: DIFF on $(uname -m)"
    head -60 /tmp/d.txt
fi
echo "=== unknown command, non-interactive ==="
php bin/app.php config -n > /tmp/u1.txt 2>&1; echo "php rc=$?"
./app config -n > /tmp/u2.txt 2>&1; echo "native rc=$?"
diff /tmp/u1.txt /tmp/u2.txt > /dev/null 2>&1 && echo "unknown-command: MATCH" || { echo "unknown-command: DIFF"; diff /tmp/u1.txt /tmp/u2.txt | head -20; }
'

docker run --rm --platform "$PLATFORM" \
    -v "$REPO":/repo:ro -v "$PROBE":/probe:ro \
    "$IMAGE" /bin/bash -c "$RUNNER"

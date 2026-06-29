#!/usr/bin/env bash
#
# Self-hosting fixpoint gate.
#
# Stage-1 (bin/manticore, built by bin/compile / Zend) compiles src/ into
# Stage-2; Stage-2 compiles src/ into Stage-3. A stable self-hosting compiler
# is a FIXPOINT: the IR Stage-2 emits for src/ must be byte-identical to the IR
# Stage-3 emits (a compiler built by the self-hosted compiler reproduces the
# self-hosted compiler exactly). Also runs the AOT suite through Stage-2 to
# confirm the self-built compiler is fully functional, not just self-stable.
#
# (The final linked binaries' md5 may differ — clang/ld embed a content UUID /
# build metadata — so the IR is the meaningful identity check.)

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -x bin/manticore ]]; then
    echo "fatal: bin/manticore missing; run bin/compile first" >&2
    exit 1
fi

echo "── Stage 2: bin/manticore compiles src ──"
bash tools/selfhost.sh bin/manticore /tmp/manticore_g2
echo "── Stage 3: Stage-2 compiles src ──"
bash tools/selfhost.sh /tmp/manticore_g2 /tmp/manticore_g3

echo "── fixpoint: Stage-2 IR == Stage-3 IR? ──"
if cmp -s /tmp/manticore_g2.ll /tmp/manticore_g3.ll; then
    echo "FIXPOINT OK: IR byte-identical across generations"
else
    echo "FIXPOINT BROKEN: Stage-2 and Stage-3 IR differ" >&2
    exit 1
fi

echo "── functional: AOT suite through the self-built Stage-2 ──"
cp bin/manticore /tmp/manticore_stage1.bak
cp /tmp/manticore_g2 bin/manticore
set +e
SUITE="$(bash tests/aot/run.sh 2>&1 | tail -1)"
set -e
cp /tmp/manticore_stage1.bak bin/manticore
echo "$SUITE"
case "$SUITE" in
    *"failed: 0"*) echo "SELF-HOST OK: self-built compiler passes the suite" ;;
    *) echo "SELF-HOST REGRESSION: self-built compiler fails the suite" >&2; exit 1 ;;
esac

# Rebuild-stability: the fixpoint above builds ONE stage-2 binary, so a
# layout-flaky rc/heap bug (crashes ~4/5 rebuilds, survives the lucky one)
# slips through. Rebuild N times through both front-ends and smoke each.
echo "── Rebuild-stability ──"
bash tools/selfhost_stability.sh 5

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
echo "── Stage 4: Stage-3 compiles src ──"
bash tools/selfhost.sh /tmp/manticore_g3 /tmp/manticore_g4

# ⚠ Compare Stage-3 against Stage-4, NOT Stage-2 against Stage-3.
# The fixpoint claim is "a SELF-HOSTED compiler reproduces itself". Stage-2 is
# built by whatever bin/manticore happens to be — and after `bin/compile`, that
# is the ZEND SEED, whose emission differs structurally (measured on Linux:
# g2 64 330 667 B vs g3 57 597 509 B, 368 181 lines apart even modulo SSA
# numbering). Stage-2 is therefore not yet self-emitted and can never match.
# g3 == g4 byte-identically, so the property holds one generation later.
# This passed on macOS only because bin/manticore there is already self-hosted
# via `bin/build`, which made Stage-2 self-emitted by accident of setup.
echo "── fixpoint: Stage-3 IR == Stage-4 IR? ──"
if cmp -s /tmp/manticore_g3.ll /tmp/manticore_g4.ll; then
    echo "FIXPOINT OK: IR byte-identical across self-hosted generations"
else
    echo "FIXPOINT BROKEN: Stage-3 and Stage-4 IR differ" >&2
    exit 1
fi

echo "── functional: AOT suite through the self-built Stage-2 ──"
# A binary swap must be a RENAME, never an in-place overwrite: macOS caches a
# mach-o's code signature per vnode, so `cp` over a compiler that has already run
# leaves every later exec SIGKILLed ("Killed: 9"). That made this stage report the
# WHOLE SUITE as failing — 710 cases, none of them actually run — and the restore
# below then handed the checkout back a compiler that could not start either.
install_binary() {
    cp "$1" "$2.swap.$$" && mv -f "$2.swap.$$" "$2"
}

cp bin/manticore /tmp/manticore_stage1.bak
# Stage-3, not Stage-2: after a cold `bin/compile` seed, Stage-2 is emitted by the
# ZEND SEED and is not self-hosted, so running the suite through it does not test
# what this stage claims to. Stage-3 is the first generation a self-hosted
# compiler produced, and it is the one the fixpoint above proves stable.
install_binary /tmp/manticore_g3 bin/manticore
set +e
SUITE="$(bash tests/aot/run.sh 2>&1 | tail -1)"
set -e
install_binary /tmp/manticore_stage1.bak bin/manticore
echo "$SUITE"
case "$SUITE" in
    *"failed: 0"*) echo "SELF-HOST OK: self-built compiler passes the suite" ;;
    *) echo "SELF-HOST REGRESSION: self-built compiler fails the suite" >&2; exit 1 ;;
esac

# MIR golden dumps — snapshots the AST->MIR lowering shape. Wired in here so it
# can never again rot unrun (it was red from v0.1.0 to the dump-mir epic because
# no gate invoked it). Trust the runner's own exit code rather than parsing its
# summary — the runner already fails non-zero on a diff, crash or empty dump.
echo "── MIR golden dumps ──"
set +e
bash tests/aot/run_mir_golden.sh > /tmp/mir_golden_gate.out 2>&1
GRC=$?
set -e
tail -1 /tmp/mir_golden_gate.out
if [[ $GRC -ne 0 ]]; then echo "MIR GOLDEN REGRESSION" >&2; exit 1; fi
echo "MIR GOLDEN OK"

# Rebuild-stability: the fixpoint above builds ONE stage-2 binary, so a
# layout-flaky rc/heap bug (crashes ~4/5 rebuilds, survives the lucky one)
# slips through. Rebuild N times through both front-ends and smoke each.
echo "── Rebuild-stability ──"
bash tools/selfhost_stability.sh "${MC_STABILITY_N:-5}"

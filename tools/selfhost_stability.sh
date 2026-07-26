#!/usr/bin/env bash
#
# Self-host REBUILD-STABILITY gate.
#
#   tools/selfhost_stability.sh [N]   (default N=8)
#
# Why this exists: the compiler binary embeds a build UUID, so every rebuild
# gets a slightly different ASLR / heap layout. A latent rc/heap bug (e.g. the
# rc-on-\Closure header clobber, commit 9d918b0) corrupts memory in a way that
# is FATAL only in some layouts — ~4/5 of rebuilds crashed at startup while the
# rest got a lucky layout. The single-run fixpoint gate is BLIND to this: it
# builds one stage-2 binary and, if that layout happens to survive, reports
# green. This gate rebuilds N times through BOTH front-ends and smoke-tests
# each binary, so the layout-roulette bug class is caught immediately instead
# of months later.
#
# Smoke per binary: `dump-llvm-mir` (exercises the front-end + the startup CLI
# command-registration path where the heisenbug surfaced) AND `compile -o` +
# run (exercises the full lower→assemble→link→execute path). Any non-zero exit
# or wrong output on any rebuild fails the gate.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Every rebuild lands in $WORK and is smoke-tested THERE — a bare binary with no
# lib/ beside it, so its argv0-relative prelude lookup finds nothing. Point it at
# the canonical dir, exactly as tools/selfhost.sh and bin/build do.
export MANTICORE_PRELUDE="$ROOT/prelude"

N="${1:-8}"

if [[ ! -x bin/manticore ]]; then
    echo "fatal: bin/manticore missing; run bin/compile first" >&2
    exit 1
fi

WORK="$(mktemp -d)"
SMOKE="$WORK/smoke.php"
printf '<?php echo "selfhost-stable\\n";\n' > "$SMOKE"

# ⚠ `bin/compile` REBUILDS THE CHECKOUT'S TOOLCHAIN as a side effect: it writes
# bin/manticore and lib/manticore_stdlib.{o,sig} even when the binary it is asked
# for goes to $WORK. Running it N times therefore replaces the compiler this script
# still needs — the self front-end loop below uses bin/manticore, and `bin/build`
# afterwards uses it too. One imperfect seed build then poisons every later stage,
# which is how a green branch (suite 684/684, difftest 0 DIFF, fixpoint + MIR golden
# OK) still ended a gate at rc=1 with `use of undefined value '@manticore_str_bytes'`
# — and left the developer's checkout unable to build itself.
#
# So: snapshot the toolchain up front and put it back on the way out, whatever
# happens. The gate stays honest (each rebuild is still smoke-tested on its own) and
# it stops being destructive.
SAVE="$WORK/toolchain"
mkdir -p "$SAVE/lib"
cp bin/manticore "$SAVE/manticore"
if compgen -G "lib/manticore_stdlib.*" >/dev/null; then
    cp lib/manticore_stdlib.* "$SAVE/lib/"
fi
restore_toolchain() {
    cp "$SAVE/manticore" bin/manticore 2>/dev/null || true
    if compgen -G "$SAVE/lib/manticore_stdlib.*" >/dev/null; then
        cp "$SAVE"/lib/manticore_stdlib.* lib/ 2>/dev/null || true
    fi
}
trap 'restore_toolchain; rm -rf "$WORK"' EXIT

# The self front-end loop must drive the SNAPSHOT, not whatever the Zend loop last
# left in bin/ — that is the binary this gate was asked to test.
STABLE_BIN="$SAVE/manticore"

# Smoke-test one compiler binary: front-end startup + full compile→run.
# Returns 0 on success; prints a diagnosis and returns 1 on any failure.
smoke() {
    local bin="$1" tag="$2" rc
    # `if ! cmd; then ... $?` always reads 0 (the negation's status) — capture the
    # real rc, and show what the compiler said instead of swallowing it.
    "$bin" dump-llvm-mir "$SMOKE" >/dev/null 2>"$WORK/smoke.err" || {
        rc=$?
        echo "  $tag: FAIL (dump-llvm-mir rc=$rc: $(head -1 "$WORK/smoke.err"))"
        return 1
    }
    local out
    "$bin" compile "$SMOKE" -o "$WORK/smoke_bin" >/dev/null 2>&1 || {
        echo "  $tag: FAIL (compile crashed, rc=$?)"; return 1; }
    out="$("$WORK/smoke_bin" 2>/dev/null)" || {
        echo "  $tag: FAIL (compiled binary crashed, rc=$?)"; return 1; }
    if [[ "$out" != "selfhost-stable" ]]; then
        echo "  $tag: FAIL (wrong output: '$out')"; return 1
    fi
    return 0
}

fail=0

echo "── Zend front-end (bin/compile) × $N rebuilds ──"
for i in $(seq 1 "$N"); do
    # Each of these rewrites bin/manticore + lib/ (see the snapshot note above).
    # `set -e` would kill the script silently on a failed rebuild — the gate then
    # exits 1 with no diagnosis at all, which is exactly what happened once. Report
    # it and keep going so the run still says WHICH front-end broke and how.
    if ! bin/compile "$WORK/zend_$i" >"$WORK/zend_$i.log" 2>&1; then
        echo "  zend-build$i: FAIL (bin/compile: $(tail -1 "$WORK/zend_$i.log"))"
        fail=$((fail + 1))
        continue
    fi
    smoke "$WORK/zend_$i" "zend-build$i" || fail=$((fail + 1))
done

echo "── self front-end (tools/selfhost.sh) × $N rebuilds ──"
for i in $(seq 1 "$N"); do
    if ! bash tools/selfhost.sh "$STABLE_BIN" "$WORK/self_$i" >"$WORK/self_$i.log" 2>&1; then
        echo "  self-build$i: FAIL (tools/selfhost.sh: $(tail -1 "$WORK/self_$i.log"))"
        fail=$((fail + 1))
        continue
    fi
    smoke "$WORK/self_$i" "self-build$i" || fail=$((fail + 1))
done

echo "──────────────────────────────────────────"
if [[ $fail -eq 0 ]]; then
    echo "STABILITY OK: ${N}×2 rebuilds, every binary smoke-clean (no layout roulette)"
else
    echo "STABILITY BROKEN: $fail of $((N * 2)) rebuilds crashed — a latent rc/heap bug is layout-flaky" >&2
    exit 1
fi

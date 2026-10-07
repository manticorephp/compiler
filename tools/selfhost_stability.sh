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
# green. This gate rebuilds N times through BOTH build paths (the manifest one
# bin/build uses, and the one-module tools/selfhost.sh) and smoke-tests
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
    echo "fatal: bin/manticore missing; run bin/build first" >&2
    exit 1
fi

WORK="$(mktemp -d)"
SMOKE="$WORK/smoke.php"
printf '<?php echo "selfhost-stable\\n";\n' > "$SMOKE"

# Snapshot the toolchain up front and put it back on the way out, whatever
# happens: every rebuild below runs from the SNAPSHOT, so nothing a failed build
# leaves behind can poison a later stage or the developer's checkout.
# Swapping a compiler binary must be a RENAME, never an in-place overwrite: on
# macOS the kernel caches a mach-o's code signature per vnode, so `cp` over a
# binary that has already run leaves every later exec SIGKILLed ("Killed: 9") —
# including the RESTORE, which would hand the developer back a checkout whose
# compiler cannot start. Writing beside it and renaming gives a fresh inode.
install_binary() {
    cp "$1" "$2.swap.$$" && mv -f "$2.swap.$$" "$2"
}

SAVE="$WORK/toolchain"
mkdir -p "$SAVE/lib"
cp bin/manticore "$SAVE/manticore"
if compgen -G "lib/manticore_stdlib.*" >/dev/null; then
    cp lib/manticore_stdlib.* "$SAVE/lib/"
fi
restore_toolchain() {
    install_binary "$SAVE/manticore" bin/manticore 2>/dev/null || true
    if compgen -G "$SAVE/lib/manticore_stdlib.*" >/dev/null; then
        cp "$SAVE"/lib/manticore_stdlib.* lib/ 2>/dev/null || true
    fi
}
trap 'restore_toolchain; rm -rf "$WORK"' EXIT

# Both loops drive the SNAPSHOT — the binary this gate was asked to test.
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

echo "── manifest build (manticore build --apps-only) × $N rebuilds ──"
for i in $(seq 1 "$N"); do
    # `set -e` would kill the script silently on a failed rebuild — the gate then
    # exits 1 with no diagnosis at all, which is exactly what happened once. Report
    # it and keep going so the run still says WHICH path broke and how.
    sed "s#\"bin/manticore\"#\"$WORK/manifest_$i\"#" manticore.json > "$WORK/manifest_$i.json"
    if ! "$STABLE_BIN" build --apps-only "$WORK/manifest_$i.json" >"$WORK/manifest_$i.log" 2>&1; then
        echo "  manifest-build$i: FAIL (manticore build: $(tail -1 "$WORK/manifest_$i.log"))"
        fail=$((fail + 1))
        continue
    fi
    smoke "$WORK/manifest_$i" "manifest-build$i" || fail=$((fail + 1))
done

echo "── one-module build (tools/selfhost.sh) × $N rebuilds ──"
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

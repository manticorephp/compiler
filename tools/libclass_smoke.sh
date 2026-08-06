#!/usr/bin/env bash
#
# Cross-module TYPES gate: a library that exports classes, interfaces, enums and
# constants, and an application that links its `.o` and uses them.
#
# Not part of tests/aot/run.sh — that runner compiles a single file or directory
# with `compile`, and the whole point here is the manifest's library/application
# split. Modelled on tools/ext_smoke.sh.
#
# The fixture is rebuilt from scratch every run. A stale `.sig` sitting next to a
# fresh `.o` is exactly the failure the ABI check exists to catch, and leaving one
# behind would make this gate flaky instead of red.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1
MC="$ROOT/bin/manticore"
FIX="$ROOT/tests/libs/classes"
WORK="$FIX/.work"

if [ ! -x "$MC" ]; then
    echo "libclass: no bin/manticore — run bin/build first"
    exit 1
fi

fails=0
note() { printf '%s\n' "$*"; }
fail() { printf 'FAIL %s\n' "$*"; fails=$((fails + 1)); }

# ── the positive fixture ──────────────────────────────────────────────────────

rm -rf "$WORK"
mkdir -p "$WORK"   # every artefact goes INSIDE the one gitignored directory
if ! "$MC" build "$FIX/manticore.json" > "$WORK/build.log" 2>&1; then
    fail "build"
    cat "$WORK/build.log"
    exit 1
fi

# Symbol split: the library DEFINES, the application only references. This is
# what proves the class really crossed as a link-time dependency instead of
# being compiled twice.
libsyms="$(nm -g "$WORK/acme.o" 2>/dev/null)"
for sym in Acme_Point____construct Acme_Point__sum Acme_Point__sp_count; do
    if ! printf '%s' "$libsyms" | grep -q "$sym"; then
        fail "library does not define $sym"
    fi
done

# Behaviour: byte-for-byte against the php interpreter, with ONE normalisation —
# `object(C)#N` object handles, which manticore numbers per-allocation-site and
# php numbers globally. That divergence predates this feature and is not what
# this gate measures.
"$WORK/acmeapp" > "$WORK/got.out" 2>&1
rc=$?
if [ "$rc" -ne 0 ]; then fail "acmeapp exited rc=$rc"; fi
sed -E 's/\)#[0-9]+/)#?/' "$FIX/expected.out" > "$WORK/exp.norm"
sed -E 's/\)#[0-9]+/)#?/' "$WORK/got.out"      > "$WORK/got.norm"
if ! diff -u "$WORK/exp.norm" "$WORK/got.norm" > "$WORK/out.diff" 2>&1; then
    fail "output differs from php"
    cat "$WORK/out.diff"
fi

# ── negative fixtures ─────────────────────────────────────────────────────────
#
# Each asserts a non-zero exit AND the diagnostic text: a refusal that fires for
# the wrong reason is not a passing test.

neg() { # <name> <expected-substring> <manifest>
    local name="$1" want="$2" manifest="$3"
    if "$MC" build "$manifest" > "$WORK/$name.log" 2>&1; then
        fail "$name: expected a refusal, build succeeded"
        return
    fi
    if ! grep -qF "$want" "$WORK/$name.log"; then
        fail "$name: refused, but not with the expected diagnostic"
        note "  wanted: $want"
        note "  got:"
        sed 's/^/    /' "$WORK/$name.log"
    fi
}

# A stale ABI. Driven through the STDLIB interface rather than the fixture
# library's: a manifest always rebuilds its library targets, so a doctored `.sig`
# next to one would be overwritten before the app ever reads it. The stdlib's is
# located by env var, goes through the same `Sig::validateImport`, and is the
# realistic case anyway — a vendored `lib/` outliving the compiler that wrote it.
mkdir -p "$WORK/abi"
sed -E 's/"abi":[0-9]+/"abi":999/' "$ROOT/lib/manticore_stdlib.o.sig" \
    > "$WORK/abi/manticore_stdlib.o.sig"
echo '<?php echo "unreachable\n";' > "$WORK/abi/t.php"
if MANTICORE_STDLIB_SIG="$WORK/abi/manticore_stdlib.o.sig" \
        "$MC" compile "$WORK/abi/t.php" -o "$WORK/abi/t" > "$WORK/abi.log" 2>&1; then
    fail "abi_stale: expected a refusal, compile succeeded"
elif ! grep -qF "was built for memory ABI 999" "$WORK/abi.log"; then
    fail "abi_stale: refused, but not with the expected diagnostic"
    sed 's/^/    /' "$WORK/abi.log"
fi

# A `.sig` from a NEWER compiler than this one.
sed -E 's/"schema":[0-9]+/"schema":99/' "$ROOT/lib/manticore_stdlib.o.sig" \
    > "$WORK/abi/newer.sig"
if MANTICORE_STDLIB_SIG="$WORK/abi/newer.sig" \
        "$MC" compile "$WORK/abi/t.php" -o "$WORK/abi/t2" > "$WORK/newer.log" 2>&1; then
    fail "schema_newer: expected a refusal, compile succeeded"
elif ! grep -qF "was written by a newer compiler" "$WORK/newer.log"; then
    fail "schema_newer: refused, but not with the expected diagnostic"
    sed 's/^/    /' "$WORK/newer.log"
fi

# A program that redeclares an exported class.
mkdir -p "$WORK/collide/app"
cat > "$WORK/collide/app/main.php" <<'PHP'
<?php
namespace Acme;
class Point { public int $z = 0; }
echo "unreachable\n";
PHP
php -r '
$fix = $argv[1]; $work = $argv[2];
$m = json_decode(file_get_contents($fix . "/manticore.json"), true);
$m["applications"][0]["src"]     = $work . "/collide/app";
$m["applications"][0]["entry"]   = $work . "/collide/app/main.php";
$m["applications"][0]["output"]  = $work . "/collide/collideapp";
file_put_contents($work . "/collide/manticore.json", json_encode($m));
' "$FIX" "$WORK"
neg collide "is declared here and also exported by a linked library" \
    "$WORK/collide/manticore.json"

# ── result ────────────────────────────────────────────────────────────────────

if [ "$fails" -eq 0 ]; then
    echo "=== RESULT: libclass OK"
    exit 0
fi
echo "=== RESULT: libclass $fails failure(s)"
exit 1

#!/usr/bin/env bash
# Installed-layout smoke gate: a compiler reached the way a USER reaches it
# still finds its prelude and its stdlib.
#
# The dev tree always calls `bin/manticore`, so argv[0] carries a slash and the
# bundled assets resolve relative to it. An install does not: on $PATH argv[0]
# is the bare name "manticore", and through /usr/local/bin it is a symlink into
# somewhere else entirely. Both used to end in `compile failed: prelude not
# found` — the container image and every release tarball, while the suite stayed
# green, because the suite never invokes the compiler the way a user does.
#
#   bash tools/install_smoke.sh [path/to/manticore]
#
# Builds a throwaway install (bin/ + lib/ copied to a temp dir, nothing shared
# with the checkout) and compiles one program through three entry points:
# bare name on $PATH, a symlink from another directory, and a relative path.
# str_pad is the probe on purpose — it is a PHP-level stdlib function, so it
# only links when BOTH the prelude and manticore_stdlib.o were found.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MC="${1:-$ROOT/bin/manticore}"
[ -x "$MC" ] || { echo "install_smoke: no compiler at $MC" >&2; exit 1; }
[ -f "$ROOT/lib/manticore_stdlib.o" ] || { echo "install_smoke: no lib/manticore_stdlib.o" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/opt/bin" "$WORK/opt/lib" "$WORK/sym"
cp "$MC" "$WORK/opt/bin/manticore"
cp -a "$ROOT/lib/." "$WORK/opt/lib/"
ln -s "$WORK/opt/bin/manticore" "$WORK/sym/manticore"

cat > "$WORK/hello.php" <<'PHP'
<?php
echo str_pad("a", 3, "-"), "\n";
PHP
EXPECTED="a--"

fail=0
check() {
    local what="$1"; shift
    rm -f "$WORK/hello"
    if ! "$@" compile "$WORK/hello.php" -o "$WORK/hello" > "$WORK/compile.log" 2>&1; then
        echo "FAIL $what — compile:"
        tail -3 "$WORK/compile.log"
        fail=1
        return
    fi
    local got
    got="$("$WORK/hello" 2>&1 || true)"
    if [ "$got" = "$EXPECTED" ]; then
        echo "PASS $what"
    else
        echo "FAIL $what — expected '$EXPECTED', got '$got'"
        fail=1
    fi
}

# A bare name: $PATH is searched, and argv[0] has no directory in it at all.
PATH="$WORK/opt/bin:$PATH" check "bare name on \$PATH" manticore
# A symlink from a directory that holds no lib/ of its own.
check "symlink from another dir" "$WORK/sym/manticore"
# The dev-tree shape, which is the one that already worked.
check "explicit path" "$WORK/opt/bin/manticore"

# The blocking-offload pool is pay-for-use: a program that never names Async\
# carries no worker thread code. An Async\ program is the control that the
# symbol names still match.
if command -v nm > /dev/null 2>&1; then
    # nm into a file, not a pipe: grep -q exits early and pipefail turns nm's
    # SIGPIPE into a failed match.
    nm "$WORK/hello" > "$WORK/hello.nm" 2>/dev/null || true
    if grep -q 'mc_pool_worker\|pthread_create' "$WORK/hello.nm"; then
        echo "FAIL pool-free hello — links the offload pool"
        fail=1
    else
        echo "PASS pool-free hello"
    fi
    cat > "$WORK/pooled.php" <<'PHP'
<?php
Async\async(function () { echo strlen((string)file_get_contents(__FILE__)) > 0 ? "y\n" : "n\n"; });
PHP
    if "$WORK/opt/bin/manticore" compile "$WORK/pooled.php" -o "$WORK/pooled" > "$WORK/compile.log" 2>&1 \
        && nm "$WORK/pooled" > "$WORK/pooled.nm" 2>/dev/null \
        && grep -q 'mc_pool_worker' "$WORK/pooled.nm"; then
        echo "PASS async program links the offload pool"
    else
        echo "FAIL async program — no offload pool worker found"
        fail=1
    fi
fi

# ---- install.sh / upgrade: mock release, no network --------------------------
MOCK="$WORK/mock"
mk_release() { # version [corrupt|noentry]
    local ver="$1" os arch name d
    eval "$(MANTICORE_VERSION=0 bash "$ROOT/install.sh" --check 2>/dev/null | sed -n 's/^platform=\(.*\)-\(.*\)$/os=\1; arch=\2/p')" || true
    [ -n "${os:-}" ] || { echo "FAIL mock: cannot read platform"; fail=1; return; }
    name="manticore-$ver-$os-$arch"
    d="$MOCK/dl/download/v$ver"
    rm -rf "$d/stage"; mkdir -p "$d/stage/$name/bin" "$d/stage/$name/share" "$MOCK/api"
    case "${2:-}" in
        badbin) printf '#!/bin/sh\nexit 1\n' > "$d/stage/$name/bin/manticore"; chmod +x "$d/stage/$name/bin/manticore";;
        nobin) ;;
        failfinal) # passes the staged smoke, fails once installed
            printf '#!/bin/sh\ncase "$0" in *.staging.*) [ "$1" = compile ] && printf "#!/bin/sh\\necho manticore-ok\\n" > "$4" && chmod +x "$4";; *) exit 1;; esac\nexit 0\n' > "$d/stage/$name/bin/manticore"; chmod +x "$d/stage/$name/bin/manticore";;
        *) cp "$MC" "$d/stage/$name/bin/manticore";;
    esac
    cp -a "$ROOT/lib" "$d/stage/$name/lib"
    cp "$ROOT/install.sh" "$d/stage/$name/share/install.sh"
    tar -C "$d/stage" -czf "$d/$name.tar.gz" "$name"
    rm -rf "$d/stage"
    local sum; sum="$( (sha256sum "$d/$name.tar.gz" 2>/dev/null || shasum -a 256 "$d/$name.tar.gz") | cut -d' ' -f1)"
    [ "${2:-}" = corrupt ] && sum="0000000000000000000000000000000000000000000000000000000000000000"
    if [ "${2:-}" = noentry ]; then : > "$d/SHA256SUMS"; else printf '%s  ./%s.tar.gz\n' "$sum" "$name" > "$d/SHA256SUMS"; fi
    printf '{"tag_name":"v%s"}\n' "$ver" > "$MOCK/api/latest"
}
mock_env() { echo "MANTICORE_API_BASE=file://$MOCK/api MANTICORE_RELEASE_BASE=file://$MOCK/dl"; }
fresh_prefix() { rm -rf "$WORK/home"; mkdir -p "$WORK/home/bin" "$WORK/home/lib"; cp "$MC" "$WORK/home/bin/manticore"; cp -a "$ROOT/lib/." "$WORK/home/lib/"; echo "old" > "$WORK/home/lib/OLD_MARKER"; }
INSTALL="env $(mock_env) MANTICORE_FROM_SOURCE=0 MANTICORE_HOME=$WORK/home bash $ROOT/install.sh"
ok() { # name, condition-exit-status
    if [ "$2" = 0 ]; then echo "PASS $1"; else echo "FAIL $1"; fail=1; fi
}
untouched() { [ -f "$WORK/home/lib/OLD_MARKER" ] && [ ! -e "$WORK/home/lib.old" ] && cmp -s "$WORK/home/bin/manticore" "$MC" \
    && [ ! -e "$WORK/home/bin/.manticore.prev" ] && ! ls -d "$WORK/home"/.staging.* >/dev/null 2>&1; }

mk_release 9.9.9
rc=0; out="$($INSTALL --check 2>/dev/null)" || rc=$?
case "$out" in *latest=9.9.9*) ok "install.sh --check: latest=9.9.9 rc 0" $rc;; *) ok "install.sh --check: latest=9.9.9 rc 0" 1;; esac
rc=0; env MANTICORE_API_BASE="file://$WORK/nowhere" MANTICORE_HOME="$WORK/home" bash "$ROOT/install.sh" --check >/dev/null 2>&1 || rc=$?
if [ "$rc" = 2 ]; then ok "install.sh --check unreachable: rc 2 (got $rc)" 0; else ok "install.sh --check unreachable: rc 2 (got $rc)" 1; fi

fresh_prefix; rc=0; MANTICORE_STRICT=1 $INSTALL >"$WORK/inst.log" 2>&1 || rc=$?
if [ "$rc" = 0 ] && [ -e "$WORK/home/bin/.manticore.prev" ] && [ -f "$WORK/home/share/install.sh" ] \
    && [ -f "$WORK/home/share/.installed-by" ] && [ ! -e "$WORK/home/lib/OLD_MARKER" ] \
    && cmp -s "$WORK/home/bin/manticore" "$MC"; then ok "strict install: swap, prev kept, share/ shipped (rc $rc)" 0; else ok "strict install: swap, prev kept, share/ shipped (rc $rc)" 1; fi

mk_release 9.9.9 corrupt; fresh_prefix; rc=0; MANTICORE_STRICT=1 $INSTALL >"$WORK/inst.log" 2>&1 || rc=$?
if [ "$rc" = 4 ] && untouched; then ok "strict checksum mismatch: rc 4, tree untouched (rc $rc)" 0; else ok "strict checksum mismatch: rc 4, tree untouched (rc $rc)" 1; fi

mk_release 9.9.9 noentry; fresh_prefix; rc=0; MANTICORE_STRICT=1 $INSTALL >"$WORK/inst.log" 2>&1 || rc=$?
if [ "$rc" = 4 ] && untouched; then ok "strict SHA256SUMS without entry: rc 4, tree untouched (rc $rc)" 0; else ok "strict SHA256SUMS without entry: rc 4, tree untouched (rc $rc)" 1; fi

fresh_prefix; rc=0; MANTICORE_VERSION=1.2.3 MANTICORE_STRICT=1 $INSTALL >"$WORK/inst.log" 2>&1 || rc=$?
if [ "$rc" = 3 ] && untouched; then ok "strict no build for version: rc 3, tree untouched (rc $rc)" 0; else ok "strict no build for version: rc 3, tree untouched (rc $rc)" 1; fi

for v in badbin nobin failfinal; do
    mk_release 9.9.9 $v; fresh_prefix; rc=0; MANTICORE_STRICT=1 $INSTALL >"$WORK/inst.log" 2>&1 || rc=$?
    if [ "$rc" = 5 ] && untouched; then ok "strict $v: rc 5, tree untouched (rc $rc)" 0; else ok "strict $v: rc 5, tree untouched (rc $rc)" 1; fi
done
rc=0; MANTICORE_SRC="$WORK/nosrc" MANTICORE_STRICT=1 $INSTALL >"$WORK/inst.log" 2>&1 || rc=$?
if [ "$rc" = 3 ]; then ok "strict + MANTICORE_SRC: rc 3 (rc $rc)" 0; else ok "strict + MANTICORE_SRC: rc 3 (rc $rc)" 1; fi

mk_release 9.9.9; rm -rf "$WORK/home"; mkdir -p "$WORK/home"; rc=0; MANTICORE_STRICT=1 $INSTALL >"$WORK/inst.log" 2>&1 || rc=$?
if [ "$rc" = 0 ] && [ "$("$WORK/home/bin/manticore" version 2>/dev/null | head -c 9)" = "manticore" ]; then ok "strict first install into empty prefix (rc $rc)" 0; else ok "strict first install into empty prefix (rc $rc)" 1; fi

rm -rf "$WORK/home"; mkdir -p "$WORK/home"; rc=0
cat "$ROOT/install.sh" | env $(mock_env) MANTICORE_FROM_SOURCE=0 MANTICORE_HOME="$WORK/home" bash >"$WORK/inst.log" 2>&1 || rc=$?
if [ "$rc" = 0 ] && [ -f "$WORK/home/share/install.sh" ]; then ok "piped (no BASH_SOURCE) non-strict install (rc $rc)" 0; else ok "piped (no BASH_SOURCE) non-strict install (rc $rc)" 1; fi
rm -rf "$MOCK" "$WORK/home" "$WORK/inst.log"

[ "$fail" = "0" ] || { echo "=== install_smoke: FAILED ==="; exit 1; }
echo "=== install_smoke: ok ==="

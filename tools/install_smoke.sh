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

[ "$fail" = "0" ] || { echo "=== install_smoke: FAILED ==="; exit 1; }
echo "=== install_smoke: ok ==="

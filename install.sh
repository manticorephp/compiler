#!/usr/bin/env bash
#
# Manticore installer — builds the compiler FROM SOURCE into $MANTICORE_HOME
# (default ~/.manticore) and tells you how to put `manticore` on your PATH.
#
#   curl -fsSL https://raw.githubusercontent.com/manticorephp/compiler/main/install.sh | bash
#   ./install.sh                      # from a checkout
#
# No prebuilt binary is downloaded — Manticore compiles ITSELF from PHP source:
#   * first install (no $MANTICORE_HOME/bin/manticore yet): cold bootstrap via
#     the Zend seed (bin/compile).
#   * upgrade (a working binary already installed): the installed compiler
#     rebuilds the new version itself (bin/build, self-host) — faster, and the
#     whole point of a self-hosting compiler; falls back to the cold seed if the
#     self build fails.
#
# Layout it produces (argv0-relative, so the binary finds its runtime with no
# env vars — see src/Manticore/Main.php find_stdlib_object / find_prelude_src):
#   $MANTICORE_HOME/bin/manticore
#   $MANTICORE_HOME/lib/manticore_stdlib.o(.sig)
#   $MANTICORE_HOME/lib/prelude/*.php
#
# Env knobs: MANTICORE_HOME, MANTICORE_REPO, MANTICORE_REF, MANTICORE_SRC.

set -euo pipefail

REPO_URL="${MANTICORE_REPO:-https://github.com/manticorephp/compiler.git}"
PREFIX="${MANTICORE_HOME:-$HOME/.manticore}"
REF="${MANTICORE_REF:-main}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mwarn:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }
have() { command -v "$1" >/dev/null 2>&1; }

# ---- 1. platform ----------------------------------------------------------
OS="$(uname -s)"; ARCH="$(uname -m)"
case "$OS" in
    Darwin|Linux) ;;
    *) die "unsupported OS: $OS (Darwin / Linux only)";;
esac
log "platform $OS/$ARCH -> prefix $PREFIX"

# ---- 2. toolchain ---------------------------------------------------------
# Hard requirements to BUILD the compiler: php (seed), clang>=15, cc. The
# stdlib's preg/TLS/hash bindings are declare-only in the object, resolved at
# link time — so pcre2/openssl/pkg-config are only needed later, when a USER
# program actually calls preg_*/https/hash. Missing them is a warning, not a
# blocker.
hard=()
have php   || hard+=("php 8.5        (the cold-bootstrap seed)")
have clang || hard+=("clang/LLVM>=15  (opaque-pointer IR)")
have cc    || hard+=("cc             (final link driver)")
soft=()
have pkg-config   || soft+=("pkg-config")
have pcre2-config || soft+=("libpcre2 dev (pcre2-config) — for preg_*")
{ ! have pkg-config || ! pkg-config --exists openssl 2>/dev/null; } \
    && soft+=("openssl 3 dev (libssl) — for https:// streams + hash/hmac")
show_hints() {
    case "$OS" in
        Darwin) echo "  xcode-select --install; brew install php pcre2 openssl@3 pkg-config" >&2;;
        Linux)  echo "  apt-get install -y gcc libc6-dev libpcre2-dev libssl-dev pkg-config netbase php8.5-cli" >&2
                echo "  # plus clang/LLVM >= 15 from https://apt.llvm.org" >&2;;
    esac
}
if [ ${#hard[@]} -gt 0 ]; then
    warn "missing build tools:"; for m in "${hard[@]}"; do warn "  - $m"; done
    show_hints; die "install the tools above, then re-run."
fi
if [ ${#soft[@]} -gt 0 ]; then
    warn "these are optional for the build but needed by programs that use them:"
    for m in "${soft[@]}"; do warn "  - $m"; done
fi
# opaque pointers need clang 15+.
cmajor="$(clang --version | sed -n 's/.*version \([0-9][0-9]*\).*/\1/p' | head -1)"
[ -n "$cmajor" ] && [ "$cmajor" -ge 15 ] 2>/dev/null \
    || die "clang ${cmajor:-?} is too old — Manticore emits opaque-pointer IR (needs LLVM >= 15)."

# ---- 3. source ------------------------------------------------------------
CLEAN_SRC=0
_script_dir="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || true)"
if [ -n "${MANTICORE_SRC:-}" ] && [ -f "$MANTICORE_SRC/bin/compile" ]; then
    SRC="$MANTICORE_SRC"
elif [ -n "$_script_dir" ] && [ -f "$_script_dir/bin/compile" ]; then
    SRC="$_script_dir"
else
    have git || die "git not found — needed to fetch source (or set MANTICORE_SRC to a checkout)."
    _tmp="$(mktemp -d)"; SRC="$_tmp/manticore"; CLEAN_SRC=1
    log "fetching $REPO_URL ($REF)"
    git clone --depth 1 --branch "$REF" "$REPO_URL" "$SRC" >/dev/null 2>&1 \
        || git clone --depth 1 "$REPO_URL" "$SRC" >/dev/null
fi
cleanup() { [ "$CLEAN_SRC" = 1 ] && rm -rf "${_tmp:-}" || true; }
trap cleanup EXIT
log "source $SRC"

# ---- 4. build -------------------------------------------------------------
# Clean slate: a stale binary/lib fakes a pass or links a foreign ABI.
rm -rf "$SRC/bin/manticore" "$SRC/lib" 2>/dev/null || true

built=0
if [ -x "$PREFIX/bin/manticore" ]; then
    log "existing install found -> self-host rebuild (installed compiler builds the new version)"
    cp "$PREFIX/bin/manticore" "$SRC/bin/manticore"
    if ( cd "$SRC" && bin/build ); then built=1
    else warn "self-host build failed -> falling back to the cold seed"; rm -rf "$SRC/bin/manticore" "$SRC/lib"; fi
fi
if [ "$built" = 0 ]; then
    log "cold bootstrap via the Zend seed (bin/compile)"
    ( cd "$SRC" && bin/compile )
fi
[ -x "$SRC/bin/manticore" ] || die "build did not produce bin/manticore"

# ---- 5. install -----------------------------------------------------------
log "installing into $PREFIX"
mkdir -p "$PREFIX/bin" "$PREFIX/lib/prelude"
cp "$SRC/bin/manticore" "$PREFIX/bin/manticore"
cp "$SRC"/lib/manticore_stdlib.o "$PREFIX/lib/"
cp "$SRC"/lib/manticore_stdlib.o.sig "$PREFIX/lib/" 2>/dev/null || true
# prelude: bin/compile installs lib/prelude itself; the self-host path does not,
# so publish it from source unconditionally (idempotent, covers both paths).
cp "$SRC"/prelude/*.php "$PREFIX/lib/prelude/"

# ---- 6. verify ------------------------------------------------------------
ver="$("$PREFIX/bin/manticore" version 2>/dev/null || true)"
printf '<?php echo "manticore-ok\\n";' > "$PREFIX/.smoke.php"
if "$PREFIX/bin/manticore" compile "$PREFIX/.smoke.php" -o "$PREFIX/.smoke" >/dev/null 2>&1 \
        && [ "$("$PREFIX/.smoke" 2>/dev/null)" = "manticore-ok" ]; then
    rm -f "$PREFIX/.smoke.php" "$PREFIX/.smoke"
    log "installed and smoke-tested: $ver"
else
    rm -f "$PREFIX/.smoke.php" "$PREFIX/.smoke"
    die "the installed compiler failed to compile+run a hello world"
fi

# ---- 7. PATH --------------------------------------------------------------
case ":$PATH:" in
    *":$PREFIX/bin:"*) log "done — run: manticore version";;
    *) log "done. Add Manticore to your PATH:"
       echo "    export PATH=\"$PREFIX/bin:\$PATH\"" >&2;;
esac

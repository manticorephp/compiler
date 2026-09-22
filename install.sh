#!/usr/bin/env bash
#
# Manticore installer — puts the compiler into $MANTICORE_HOME (default
# ~/.manticore) and tells you how to put `manticore` on your PATH.
#
#   curl -fsSL https://raw.githubusercontent.com/manticorephp/compiler/main/install.sh | bash
#   ./install.sh                      # from a checkout
#
# Two paths, in this order:
#   * a PUBLISHED build for this platform (linux/macos × arm64/amd64), verified
#     against the release's SHA256SUMS. Needs no php: the compiler is native and
#     CI already paid the bootstrap. MANTICORE_FROM_SOURCE=1 skips it.
#   * otherwise Manticore compiles ITSELF from PHP source:
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
# Env knobs: MANTICORE_HOME, MANTICORE_REPO, MANTICORE_REF, MANTICORE_SRC,
#           MANTICORE_VERSION (a specific release), MANTICORE_FROM_SOURCE=1.

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

# ---- 1b. a published build, if there is one for this platform -------------
# The fast path, and since 0.11 the usual one: a release tarball is steps 3-5
# below, already done on CI and cold-seeded from the tag. It costs a download
# instead of a bootstrap, and it needs no php at all — the compiler is native.
# Skipped for an explicitly source-flavoured install (MANTICORE_SRC, a
# non-default MANTICORE_REF, MANTICORE_FROM_SOURCE=1), and ANY miss falls
# through to the build rather than failing: a platform with no published build,
# an unreachable github, a checksum that does not match.
REL_OS=""; REL_ARCH=""
case "$OS" in Darwin) REL_OS=macos;; Linux) REL_OS=linux;; esac
case "$ARCH" in arm64|aarch64) REL_ARCH=arm64;; x86_64|amd64) REL_ARCH=amd64;; esac

fetch() {
    if have curl; then curl -fsSL "$1" -o "$2"
    elif have wget; then wget -qO "$2" "$1"
    else return 1
    fi
}

try_prebuilt() {
    [ "${MANTICORE_FROM_SOURCE:-0}" = 0 ] || return 1
    [ -z "${MANTICORE_SRC:-}" ] || return 1
    [ "$REF" = main ] || return 1
    [ -n "$REL_OS" ] && [ -n "$REL_ARCH" ] || return 1
    have curl || have wget || return 1

    local api="https://api.github.com/repos/manticorephp/compiler/releases"
    local dl="https://github.com/manticorephp/compiler/releases"
    local tmp ver base name
    tmp="$(mktemp -d)"

    ver="${MANTICORE_VERSION:-}"
    if [ -z "$ver" ]; then
        fetch "$api/latest" "$tmp/latest.json" || { rm -rf "$tmp"; return 1; }
        ver="$(sed -n 's/.*"tag_name"[^"]*"v\{0,1\}\([^"]*\)".*/\1/p' "$tmp/latest.json" | head -1)"
    fi
    [ -n "$ver" ] || { rm -rf "$tmp"; return 1; }

    base="$dl/download/v$ver"
    name="manticore-$ver-$REL_OS-$REL_ARCH"
    log "published build $ver ($REL_OS/$REL_ARCH) — downloading (MANTICORE_FROM_SOURCE=1 to build instead)"
    fetch "$base/$name.tar.gz" "$tmp/$name.tar.gz" || { rm -rf "$tmp"; return 1; }

    # An unverified download is still better than no install, but say which it was.
    if fetch "$base/SHA256SUMS" "$tmp/SHA256SUMS" 2>/dev/null; then
        local want got sum
        sum=""
        have sha256sum && sum="sha256sum"
        [ -n "$sum" ] || { have shasum && sum="shasum -a 256"; }
        if [ -n "$sum" ]; then
            want="$(sed -n "s|^\([0-9a-f]\{64\}\)[ *]*\./\{0,1\}$name\.tar\.gz\$|\1|p" "$tmp/SHA256SUMS" | head -1)"
            got="$($sum "$tmp/$name.tar.gz" | cut -d' ' -f1)"
            if [ -n "$want" ] && [ "$want" != "$got" ]; then
                warn "checksum mismatch for $name.tar.gz — building from source instead"
                rm -rf "$tmp"; return 1
            fi
        else
            warn "no sha256sum/shasum here — the download is unverified"
        fi
    fi

    tar -xzf "$tmp/$name.tar.gz" -C "$tmp" || { rm -rf "$tmp"; return 1; }
    [ -x "$tmp/$name/bin/manticore" ] || { rm -rf "$tmp"; return 1; }

    log "installing into $PREFIX"
    mkdir -p "$PREFIX/bin" "$PREFIX/lib"
    rm -rf "$PREFIX/lib/prelude"
    # macOS refuses to overwrite a RUNNING or signed binary in place (SIGKILL);
    # removing first is the difference between an upgrade and a dead install.
    rm -f "$PREFIX/bin/manticore"
    cp "$tmp/$name/bin/manticore" "$PREFIX/bin/manticore"
    cp -R "$tmp/$name/lib/." "$PREFIX/lib/"
    if [ "$OS" = Darwin ]; then
        # Downloaded and unsigned: without this Gatekeeper answers with a dialog
        # about an unverified developer, which names nothing that would fix it.
        xattr -d com.apple.quarantine "$PREFIX/bin/manticore" 2>/dev/null || true
    fi
    rm -rf "$tmp"
    return 0
}

PREBUILT=0
if try_prebuilt; then PREBUILT=1; fi

# ---- 2. toolchain ---------------------------------------------------------
# Hard requirements to BUILD the compiler: php (seed), clang>=15, cc. The
# stdlib's preg/TLS/hash bindings are declare-only in the object, resolved at
# link time — so pcre2/openssl/pkg-config are only needed later, when a USER
# program actually calls preg_*/https/hash. Missing them is a warning, not a
# blocker.
hard=()
# php seeds the bootstrap and nothing else — a published build has already been
# through it, so an install that took the fast path does not want php at all.
[ "$PREBUILT" = 1 ] || have php || hard+=("php 8.5        (the cold-bootstrap seed)")
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

# ---- 3-5. source, build, install ------------------------------------------
# Everything below the guard is the FROM-SOURCE path; a published build has
# already landed in $PREFIX. Left unindented on purpose — the diff that added
# the guard should not be a diff that rewrote the build.
if [ "$PREBUILT" = 0 ]; then

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

fi   # end of the from-source path

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

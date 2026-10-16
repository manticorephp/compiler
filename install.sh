#!/usr/bin/env bash
#
# Manticore installer — puts the compiler into $MANTICORE_HOME (default
# ~/.manticore) and tells you how to put `manticore` on your PATH.
#
#   curl -fsSL https://raw.githubusercontent.com/manticorephp/compiler/main/install.sh | bash
#   ./install.sh                      # from a checkout
#
# Two paths, in this order:
#   * a PUBLISHED build for this platform (linux/linux-musl/macos × arm64/amd64), verified
#     against the release's SHA256SUMS. Needs no php: the compiler is native and
#     CI already paid the bootstrap. MANTICORE_FROM_SOURCE=1 skips it.
#   * otherwise Manticore compiles ITSELF from PHP source (bin/build):
#   * upgrade (a working binary already installed): the installed compiler
#     rebuilds the new version itself — the whole point of a self-hosting
#     compiler.
#   * first install, or the installed compiler is too old: bin/build fetches
#     the pinned bootstrap release (BOOTSTRAP_VERSION) and builds from it.
#
# Layout it produces (argv0-relative, so the binary finds its runtime with no
# env vars — see src/Manticore/Main.php find_stdlib_object / find_prelude_src):
#   $MANTICORE_HOME/bin/manticore
#   $MANTICORE_HOME/lib/manticore_stdlib.o(.sig)
#   $MANTICORE_HOME/lib/prelude/*.php
#
# Env knobs: MANTICORE_HOME, MANTICORE_REPO, MANTICORE_REF, MANTICORE_SRC,
#           MANTICORE_VERSION (a specific release), MANTICORE_FROM_SOURCE=1,
#           MANTICORE_STRICT=1 (upgrade: a miss is fatal, exit 2 net / 3 no build /
#           4 checksum / 5 stage), MANTICORE_API_BASE, MANTICORE_RELEASE_BASE.
# Flags: --check prints latest=<ver> and platform=<os>-<arch>, installs nothing.

set -euo pipefail

REPO_URL="${MANTICORE_REPO:-https://github.com/manticorephp/compiler.git}"
PREFIX="${MANTICORE_HOME:-$HOME/.manticore}"
REF="${MANTICORE_REF:-main}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mwarn:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }
have() { command -v "$1" >/dev/null 2>&1; }

STRICT="${MANTICORE_STRICT:-0}"
API_BASE="${MANTICORE_API_BASE:-https://api.github.com/repos/manticorephp/compiler/releases}"
DL_BASE="${MANTICORE_RELEASE_BASE:-https://github.com/manticorephp/compiler/releases}"
EX_NET=2; EX_NOBUILD=3; EX_SUM=4; EX_STAGE=5
SELF="${BASH_SOURCE[0]:-}"
CHECK=0
for _a in "$@"; do if [ "$_a" = "--check" ]; then CHECK=1; fi; done

die_code() { printf '\033[1;31merror:\033[0m %s\n' "$2" >&2; exit "$1"; }
# Strict (upgrade): a miss is fatal with its own exit code. Otherwise warn and
# let the caller fall back to a source build, as before.
miss() { if [ "$STRICT" = 1 ]; then die_code "$1" "$2"; fi; warn "$2"; }

# ---- 1. platform ----------------------------------------------------------
OS="$(uname -s)"; ARCH="$(uname -m)"
case "$OS" in
    Darwin|Linux) ;;
    *) die "unsupported OS: $OS (Darwin / Linux only)";;
esac
log "platform $OS/$ARCH -> prefix $PREFIX"

# ---- 1b. a published build, if there is one for this platform -------------
# The fast path, and since 0.11 the usual one: a release tarball is steps 3-5
# below, already done on CI and built from the pinned release. It costs a download
# instead of a bootstrap, and it needs no php at all — the compiler is native.
# Skipped for an explicitly source-flavoured install (MANTICORE_SRC, a
# non-default MANTICORE_REF, MANTICORE_FROM_SOURCE=1), and ANY miss falls
# through to the build rather than failing: a platform with no published build,
# an unreachable github, a checksum that does not match.
REL_OS=""; REL_ARCH=""
case "$OS" in Darwin) REL_OS=macos;; Linux) REL_OS=linux;; esac
# The C library, not the kernel, decides which Linux build starts here: a glibc
# binary under musl fails in the loader with "not found" and nothing more.
# musl's ldd names itself on stderr; the loader file is the fallback for a host
# without ldd.
MUSL=0
if [ "$OS" = Linux ]; then
    if { ldd --version 2>&1 || true; } | grep -qi musl || ls /lib/ld-musl-* >/dev/null 2>&1; then
        MUSL=1; REL_OS=linux-musl
    fi
fi
case "$ARCH" in arm64|aarch64) REL_ARCH=arm64;; x86_64|amd64) REL_ARCH=amd64;; esac

fetch() {
    if have curl; then curl -fsSL "$1" -o "$2"
    elif have wget; then wget -qO "$2" "$1"
    else return 1
    fi
}

resolve_version() { # $1 = scratch dir; sets VER
    VER="${MANTICORE_VERSION:-}"; VER="${VER#v}"
    if [ -z "$VER" ]; then
        fetch "$API_BASE/latest" "$1/latest.json" || return 1
        VER="$(sed -n 's/.*"tag_name"[^"]*"v\{0,1\}\([^"]*\)".*/\1/p' "$1/latest.json" | head -1)"
    fi
    [ -n "$VER" ]
}

smoke_compile() { # $1 compiler, $2 scratch dir
    printf '<?php echo "manticore-ok\\n";' > "$2/.smoke.php"
    if "$1" compile "$2/.smoke.php" -o "$2/.smoke" >/dev/null 2>&1 \
            && [ "$("$2/.smoke" 2>/dev/null)" = "manticore-ok" ]; then
        rm -f "$2/.smoke.php" "$2/.smoke"; return 0
    fi
    rm -f "$2/.smoke.php" "$2/.smoke"; return 1
}

# Staged swap: everything is already verified and smoke-tested in $1 (inside
# $PREFIX, so each mv is a same-filesystem rename). rm+mv, never cp over the binary.
# Runs under `if`/`||`, where errexit is off: every step that matters returns 1.
swap_in() {
    local stage="$1"
    mkdir -p "$PREFIX/bin" "$PREFIX/share" || return 1
    rm -rf "$PREFIX/lib.old"
    rm -f "$PREFIX/bin/.manticore.prev"
    if [ -e "$PREFIX/bin/manticore" ]; then mv "$PREFIX/bin/manticore" "$PREFIX/bin/.manticore.prev" || return 1; fi
    rm -rf "$PREFIX/lib.old"
    if [ -d "$PREFIX/lib" ]; then mv "$PREFIX/lib" "$PREFIX/lib.old" || return 1; fi
    mv "$stage/bin/manticore" "$PREFIX/bin/manticore" || return 1
    mv "$stage/lib" "$PREFIX/lib" || return 1
    if [ -f "$stage/share/install.sh" ]; then
        cp "$stage/share/install.sh" "$PREFIX/share/install.sh.new" && mv "$PREFIX/share/install.sh.new" "$PREFIX/share/install.sh" || return 1
    fi
    mv "$stage/share/.installed-by" "$PREFIX/share/.installed-by" || return 1
    rm -rf "$stage"
    return 0
}
rollback() {
    if [ ! -e "$PREFIX/bin/.manticore.prev" ] && [ ! -d "$PREFIX/lib.old" ]; then return 0; fi
    warn "rolling back to the previous install"
    if [ -d "$PREFIX/lib.old" ]; then rm -rf "$PREFIX/lib"; mv "$PREFIX/lib.old" "$PREFIX/lib"; fi
    if [ -e "$PREFIX/bin/.manticore.prev" ]; then rm -f "$PREFIX/bin/manticore"; mv "$PREFIX/bin/.manticore.prev" "$PREFIX/bin/manticore"; fi
    return 0
}

if [ "$CHECK" = 1 ]; then
    _t="$(mktemp -d)"
    resolve_version "$_t" || { rm -rf "$_t"; die_code $EX_NET "cannot resolve the latest release (is github reachable?)"; }
    rm -rf "$_t"
    echo "latest=$VER"; echo "platform=${REL_OS:-?}-${REL_ARCH:-?}"; exit 0
fi

try_prebuilt() {
    if [ "${MANTICORE_FROM_SOURCE:-0}" != 0 ] || [ -n "${MANTICORE_SRC:-}" ] || [ "$REF" != main ]; then
        if [ "$STRICT" = 1 ]; then die_code $EX_NOBUILD "strict mode needs a published build; unset MANTICORE_FROM_SOURCE/MANTICORE_SRC/MANTICORE_REF"; fi
        return 1
    fi
    if [ -z "$REL_OS" ] || [ -z "$REL_ARCH" ]; then miss $EX_NOBUILD "no published build for $OS/$ARCH"; return 1; fi
    if ! have curl && ! have wget; then miss $EX_NET "neither curl nor wget found"; return 1; fi
    local tmp name base want got sum stage rel_ver
    tmp="$(mktemp -d)"
    if ! resolve_version "$tmp"; then rm -rf "$tmp"; miss $EX_NET "cannot resolve the release version (is github reachable?)"; return 1; fi
    rel_ver="$VER"
    base="$DL_BASE/download/v$rel_ver"
    name="manticore-$rel_ver-$REL_OS-$REL_ARCH"
    log "published build $rel_ver ($REL_OS/$REL_ARCH) — downloading (MANTICORE_FROM_SOURCE=1 to build instead)"
    if ! fetch "$base/$name.tar.gz" "$tmp/$name.tar.gz"; then
        rm -rf "$tmp"; miss $EX_NOBUILD "no published build $name (or the download failed)"; return 1
    fi

    sum=""
    have sha256sum && sum="sha256sum"
    [ -n "$sum" ] || { have shasum && sum="shasum -a 256"; }
    if [ -z "$sum" ]; then
        if [ "$STRICT" = 1 ]; then rm -rf "$tmp"; die_code $EX_SUM "no sha256sum/shasum here — cannot verify the download"; fi
        warn "no sha256sum/shasum here — the download is unverified"
    elif fetch "$base/SHA256SUMS" "$tmp/SHA256SUMS" 2>/dev/null; then
        want="$(sed -n "s|^\([0-9a-f]\{64\}\)[ *]*\./\{0,1\}$name\.tar\.gz\$|\1|p" "$tmp/SHA256SUMS" | head -1)"
        got="$($sum "$tmp/$name.tar.gz" | cut -d' ' -f1)"
        if [ -z "$want" ] && [ "$STRICT" = 1 ]; then rm -rf "$tmp"; die_code $EX_SUM "SHA256SUMS has no entry for $name.tar.gz"; fi
        if [ -n "$want" ] && [ "$want" != "$got" ]; then rm -rf "$tmp"; miss $EX_SUM "checksum mismatch for $name.tar.gz"; return 1; fi
    elif [ "$STRICT" = 1 ]; then
        rm -rf "$tmp"; die_code $EX_SUM "cannot fetch SHA256SUMS — refusing an unverified download"
    fi

    if ! tar -xzf "$tmp/$name.tar.gz" -C "$tmp" || [ ! -x "$tmp/$name/bin/manticore" ]; then
        rm -rf "$tmp"; miss $EX_STAGE "the tarball is not a Manticore build"; return 1
    fi
    stage="$PREFIX/.staging.$$"
    rm -rf "$stage"; mkdir -p "$stage/share"
    cp -R "$tmp/$name/bin" "$tmp/$name/lib" "$stage/"
    if [ -f "$tmp/$name/share/install.sh" ]; then cp "$tmp/$name/share/install.sh" "$stage/share/install.sh"
    elif [ -n "$SELF" ] && [ -f "$SELF" ]; then cp "$SELF" "$stage/share/install.sh"; fi
    printf 'install.sh %s\n' "$rel_ver" > "$stage/share/.installed-by"
    if [ "$OS" = Darwin ]; then xattr -d com.apple.quarantine "$stage/bin/manticore" 2>/dev/null || true; fi
    if ! smoke_compile "$stage/bin/manticore" "$stage"; then
        rm -rf "$tmp" "$stage"; miss $EX_STAGE "the downloaded compiler failed its smoke test; the installed one is untouched"; return 1
    fi
    log "installing into $PREFIX"
    if ! swap_in "$stage"; then
        rollback; rm -rf "$tmp" "$stage"; miss $EX_STAGE "the swap failed; the previous install was restored"; return 1
    fi
    rm -rf "$tmp"
    return 0
}

PREBUILT=0
if try_prebuilt; then PREBUILT=1; fi

# ---- 2. toolchain ---------------------------------------------------------
# Hard requirements to BUILD the compiler: clang>=15, cc. The
# stdlib's preg/TLS/hash bindings are declare-only in the object, resolved at
# link time — so pcre2/openssl/pkg-config are only needed later, when a USER
# program actually calls preg_*/https/hash. Missing them is a warning, not a
# blocker.
hard=()
# php is not on the list: a source build starts from the pinned release.
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
        Linux)  if [ "$MUSL" = 1 ]; then
                    echo "  apk add clang lld gcc musl-dev pcre2-dev openssl-dev zlib-dev pkgconf tzdata libxml2-dev php85 php85-ctype" >&2
                else
                    echo "  apt-get install -y gcc libc6-dev libpcre2-dev libssl-dev zlib1g-dev pkg-config netbase php8.5-cli" >&2
                    echo "  # plus clang/LLVM >= 15 (clang-NN from the distribution archive)" >&2
                fi;;
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
if [ -n "${MANTICORE_SRC:-}" ] && [ -f "$MANTICORE_SRC/bin/build" ]; then
    SRC="$MANTICORE_SRC"
elif [ -n "$_script_dir" ] && [ -f "$_script_dir/bin/build" ] && [ -d "$_script_dir/src" ]; then
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
    else warn "self-host build failed -> starting from the pinned release"; rm -rf "$SRC/bin/manticore" "$SRC/lib"; fi
fi
if [ "$built" = 0 ]; then
    log "bootstrap from the pinned release (bin/build, BOOTSTRAP_VERSION)"
    ( cd "$SRC" && bin/build ) || die "bin/build failed — see above."
fi
[ -x "$SRC/bin/manticore" ] || die "build did not produce bin/manticore"

# ---- 5. install -----------------------------------------------------------
log "installing into $PREFIX"
mkdir -p "$PREFIX/bin" "$PREFIX/lib/prelude"
cp "$SRC/bin/manticore" "$PREFIX/bin/manticore"
cp "$SRC"/lib/manticore_stdlib.o "$PREFIX/lib/"
cp "$SRC"/lib/manticore_stdlib.o.sig "$PREFIX/lib/" 2>/dev/null || true
# prelude: published from source unconditionally (idempotent), so the installed
# layout never depends on what the build left under lib/prelude.
cp "$SRC"/prelude/*.php "$PREFIX/lib/prelude/"
mkdir -p "$PREFIX/share"; cp "$SRC/install.sh" "$PREFIX/share/install.sh"; printf 'install.sh source\n' > "$PREFIX/share/.installed-by"
# ponytail: source builds copy in place, not staged; stage them like the prebuilt path if upgrade ever builds from source

fi   # end of the from-source path

# ---- 6. verify ------------------------------------------------------------
ver="$("$PREFIX/bin/manticore" version 2>/dev/null || true)"
if smoke_compile "$PREFIX/bin/manticore" "$PREFIX"; then
    rm -rf "$PREFIX/lib.old"
    log "installed and smoke-tested: $ver"
else
    # Only undo a swap THIS run made; a source build leaves no staged state to restore.
    if [ "$PREBUILT" = 1 ]; then rollback; fi
    if [ "$STRICT" = 1 ]; then die_code $EX_STAGE "the installed compiler failed to compile+run a hello world"; fi
    die "the installed compiler failed to compile+run a hello world"
fi

# ---- 7. PATH --------------------------------------------------------------
case ":$PATH:" in
    *":$PREFIX/bin:"*) log "done — run: manticore version";;
    *) log "done. Add Manticore to your PATH:"
       echo "    export PATH=\"$PREFIX/bin:\$PATH\"" >&2;;
esac

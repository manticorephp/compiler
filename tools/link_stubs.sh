#!/usr/bin/env bash
#
# link_stubs.sh <output> <obj> [extra.o ...]
#
# Link the given objects into <output>, generating void*-returning stubs for
# every symbol the linker can't resolve. This is the runtime-free bootstrap
# tail: the compiler references native FFI-boundary primitives
# (`manticore_rt_*`) that have no implementation in the pure-PHP tree;
# they link-stub to 0 (and only fire at runtime if actually called).
#
# THE one implementation — bin/compile and tools/selfhost.sh both call this.
# It used to be copy-pasted into all three, in an Apple-ld-only form, which is
# why the Linux seed link failed (issue #1).
#
# STUBS_PREFIX (optional): path prefix for the generated <prefix>_stubs.{c,o}.
# Defaults to a mktemp path. Callers that want deterministic artifact paths set
# it; nothing is compiled with -g, so this never reaches the output binary.

set -uo pipefail

OUT="$1"; shift
OBJS=("$@")

PREFIX="${STUBS_PREFIX:-$(mktemp)}"
STUBS_C="${PREFIX}_stubs.c"
STUBS_O="${PREFIX}_stubs.o"

# Probe the linker without stubs; capture the undefined-symbol report.
#
# LC_ALL=C is load-bearing, not hygiene: GNU ld TRANSLATES its diagnostics, so
# under e.g. fr_FR the message is `référence indéfinie vers « sym »` and any
# pattern written against the English text silently matches nothing.
LINK_ERR="$(LC_ALL=C LANG=C cc "${OBJS[@]}" -o /dev/null 2>&1)"
PROBE_RC=$?

# Three linker dialects report the same condition three ways:
#
#   Apple ld    "_pcre2_compile_8", referenced from:
#   GNU ld      mir:(.text+0x31cb64): undefined reference to `pcre2_compile_8'
#   lld         error: undefined symbol: pcre2_compile_8
#
# Mach-O mangles names with a leading underscore, ELF does not — hence the
# `_?` in the entry-point filter and the sub() that strips it.
extract_undefined() {
    {
        printf '%s\n' "$1" | sed -nE 's/^[[:space:]]*"([^"]+)", referenced from:.*/\1/p'
        printf '%s\n' "$1" | sed -nE "s/.*undefined reference to \`([^']+)'.*/\1/p"
        printf '%s\n' "$1" | sed -nE 's/.*undefined symbol: ([A-Za-z0-9_.$]+).*/\1/p'
    } | sort -u | grep -vE '^_?(main|manticore_cli_argc|manticore_cli_argv)$'
}

SYMS="$(extract_undefined "$LINK_ERR")"

# A silently EMPTY stubs.c is the failure mode issue #1 presented as: the probe
# reported undefined symbols, extraction matched none of them, and the link
# below then died on the very symbols this stage exists to stub. Fail here,
# where the cause is visible, instead of one stage later where it is not.
#
# Gate on the probe's EXIT CODE, not on LINK_ERR being non-empty: a successful
# link that merely emitted warnings needs no stubs and must not trip this.
if [[ "$PROBE_RC" -ne 0 && -z "$SYMS" ]]; then
    echo "link_stubs.sh: linker reported errors but no undefined symbols were extracted." >&2
    echo "link_stubs.sh: unrecognised linker diagnostic format — raw output follows." >&2
    printf '%s\n' "$LINK_ERR" >&2
    exit 1
fi

# ── Host FFI libraries, resolved from the UNDEFINED SET ────────────────────
#
# Stubbing is for symbols nothing on the host can supply (the `manticore_rt_*`
# FFI-boundary primitives, the fiber trampoline). It is NOT for libraries that
# are installed: a void* stub for `pcre2_compile_8` returns 0, which the stdlib
# reads as "pattern did not compile" — every preg_* call in the binary then
# answers "no match", silently. That is what emptied the compiler's walker-root
# scan in every self-hosted generation ({@see \Manticore\lower_module}), which
# in turn dropped every class arm from the generated var_dump / var_export /
# serialize walkers: 20 AOT cases red through a stage binary, green through the
# manifest-built one, with no diagnostic anywhere.
#
# Demand-driven, exactly like the driver's `#[Ffi\Library]` handling: the
# undefined names decide which library to add. The resolvers mirror
# {@see \Manticore\pcre2_link_flags}, {@see \Manticore\openssl_link_flags} and
# {@see \Manticore\iconv_link_flags} — Homebrew keeps pcre2 and openssl@3 off
# the default search path, and glibc implements iconv inside libc.
_pcre2_flags() {
    local f
    f="$(pcre2-config --libs8 2>/dev/null)"
    if [[ -n "$f" ]]; then printf '%s' "$f"; return; fi
    local d
    for d in /opt/homebrew/opt/pcre2/lib /usr/local/opt/pcre2/lib; do
        if [[ -f "$d/libpcre2-8.dylib" ]]; then printf -- '-L%s -lpcre2-8' "$d"; return; fi
    done
    printf -- '-lpcre2-8'
}

_openssl_flags() {
    local f
    f="$(pkg-config --libs openssl 2>/dev/null)"
    if [[ -n "$f" ]]; then printf '%s' "$f"; return; fi
    local d
    for d in /opt/homebrew/opt/openssl@3/lib /usr/local/opt/openssl@3/lib; do
        if [[ -f "$d/libssl.dylib" && -f "$d/libcrypto.dylib" ]]; then
            printf -- '-L%s -lssl -lcrypto' "$d"; return
        fi
    done
    printf -- '-lssl -lcrypto'
}

_iconv_flags() {
    if [[ "$(uname -s)" == "Darwin" ]]; then printf -- '-liconv'; fi
}

EXTRA_LIBS=""
if printf '%s\n' "$SYMS" | grep -qE '^_?pcre2_'; then
    EXTRA_LIBS="$EXTRA_LIBS $(_pcre2_flags)"
fi
if printf '%s\n' "$SYMS" | grep -qE '^_?(SSL_|TLS_|EVP_|HMAC$|OPENSSL_)'; then
    EXTRA_LIBS="$EXTRA_LIBS $(_openssl_flags)"
fi
if printf '%s\n' "$SYMS" | grep -qE '^_?iconv(_open|_close)?$'; then
    EXTRA_LIBS="$EXTRA_LIBS $(_iconv_flags)"
fi

# Re-probe with the libraries on the line: what they resolve must NOT be
# stubbed, and what they leave undefined still must be.
if [[ -n "${EXTRA_LIBS// /}" ]]; then
    LINK_ERR="$(LC_ALL=C LANG=C cc "${OBJS[@]}" $EXTRA_LIBS -o /dev/null 2>&1)"
    PROBE_RC=$?
    SYMS="$(extract_undefined "$LINK_ERR")"
    if [[ "$PROBE_RC" -ne 0 && -z "$SYMS" ]]; then
        echo "link_stubs.sh: linker reported errors but no undefined symbols were extracted." >&2
        printf '%s\n' "$LINK_ERR" >&2
        exit 1
    fi
fi
printf '%s\n' "$SYMS" \
    | awk 'NF { name=$1; sub(/^_/, "", name); print "void* "name"() { return 0; }" }' > "$STUBS_C"

clang -c "$STUBS_C" -o "$STUBS_O"
cc "${OBJS[@]}" "$STUBS_O" $EXTRA_LIBS -o "$OUT"

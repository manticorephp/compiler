#!/usr/bin/env bash
#
# link_stubs.sh <output> <obj> [extra.o ...]
#
# Link the given objects into <output>, generating void*-returning stubs for
# every symbol the linker can't resolve. This is the runtime-free bootstrap
# tail: the compiler references native FFI-boundary primitives
# (`manticore_rt_*`) that have no implementation on the no-Rust php/v2 branch;
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

# Three linker dialects report the same condition three ways:
#
#   Apple ld    "_pcre2_compile_8", referenced from:
#   GNU ld      mir:(.text+0x31cb64): undefined reference to `pcre2_compile_8'
#   lld         error: undefined symbol: pcre2_compile_8
#
# Mach-O mangles names with a leading underscore, ELF does not — hence the
# `_?` in the entry-point filter and the sub() that strips it.
SYMS="$({
    printf '%s\n' "$LINK_ERR" | sed -nE 's/^[[:space:]]*"([^"]+)", referenced from:.*/\1/p'
    printf '%s\n' "$LINK_ERR" | sed -nE "s/.*undefined reference to \`([^']+)'.*/\1/p"
    printf '%s\n' "$LINK_ERR" | sed -nE 's/.*undefined symbol: ([A-Za-z0-9_.$]+).*/\1/p'
} | sort -u | grep -vE '^_?(main|manticore_cli_argc|manticore_cli_argv)$')"

# A silently EMPTY stubs.c is the failure mode issue #1 presented as: the probe
# reported undefined symbols, extraction matched none of them, and the link
# below then died on the very symbols this stage exists to stub. Fail here,
# where the cause is visible, instead of one stage later where it is not.
if [[ -n "$LINK_ERR" && -z "$SYMS" ]]; then
    echo "link_stubs.sh: linker reported errors but no undefined symbols were extracted." >&2
    echo "link_stubs.sh: unrecognised linker diagnostic format — raw output follows." >&2
    printf '%s\n' "$LINK_ERR" >&2
    exit 1
fi

printf '%s\n' "$SYMS" \
    | awk 'NF { name=$1; sub(/^_/, "", name); print "void* "name"() { return 0; }" }' > "$STUBS_C"

clang -c "$STUBS_C" -o "$STUBS_O"
cc "${OBJS[@]}" "$STUBS_O" -o "$OUT"

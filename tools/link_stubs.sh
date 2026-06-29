#!/usr/bin/env bash
#
# link_stubs.sh <output> <obj> [extra.o ...]
#
# Link the given objects into <output>, generating void*-returning stubs for
# every symbol the linker can't resolve. This is the runtime-free bootstrap
# tail: the compiler references native FFI-boundary primitives
# (`manticore_rt_*`) that have no implementation on the no-Rust php/v2 branch;
# they link-stub to 0 (and only fire at runtime if actually called). Shared by
# bin/compile and the `manticore build` application link.
#
# macOS prefixes symbols with `_` in linker errors; Linux does not — strip a
# leading `_` defensively.

set -uo pipefail

OUT="$1"; shift
OBJS=("$@")

STUBS_C="$(mktemp)_stubs.c"
STUBS_O="${STUBS_C%.c}.o"

# Probe the linker without stubs; capture the undefined-symbol report.
LINK_ERR="$(cc "${OBJS[@]}" -o /dev/null 2>&1)"

echo "$LINK_ERR" \
    | grep '^  "_' \
    | awk -F'"' '{print $2}' \
    | sort -u \
    | grep -vE '^_(main|manticore_cli_argc|manticore_cli_argv)$' \
    | awk '{name=$1; sub(/^_/, "", name); print "void* "name"() { return 0; }"}' > "$STUBS_C" \
    || true

clang -c "$STUBS_C" -o "$STUBS_O"
cc "${OBJS[@]}" "$STUBS_O" -o "$OUT"

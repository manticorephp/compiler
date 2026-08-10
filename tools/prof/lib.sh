#!/usr/bin/env bash
#
# Shared helpers for the tools/prof/ harnesses.
#
# Sourced, never executed. Everything here is host-tool plumbing: the compiler
# is measured from OUTSIDE, exactly as `Stats`'s own docblock prescribes
# ("sample RSS from outside the process and correlate on the elapsed-ms column").

# Peak RSS in bytes of one run of "$@". Darwin reports bytes via `time -l`,
# glibc reports KiB via `time -v`; the Debian toolchain image has neither, and
# says so instead of printing a wrong number. Same shape as bench/run.sh:82.
mc_max_rss_bytes() {
    local out
    if [[ -x /usr/bin/time ]]; then
        out=$( { /usr/bin/time -l "$@" >/dev/null; } 2>&1 )
        if printf '%s\n' "$out" | grep -q 'maximum resident set size'; then
            printf '%s\n' "$out" | awk '/maximum resident set size/{print $1; exit}'
            return
        fi
        out=$( { /usr/bin/time -v "$@" >/dev/null; } 2>&1 )
        if printf '%s\n' "$out" | grep -q 'Maximum resident set size'; then
            printf '%s\n' "$out" \
                | awk -F: '/Maximum resident set size/{gsub(/[ \t]/,"",$2); print $2*1024; exit}'
            return
        fi
    fi
    echo "prof: no usable /usr/bin/time on this host" >&2
    return 1
}

mc_mb() { awk -v b="$1" 'BEGIN{printf "%.0f", b/1048576}'; }

# The compiler's prelude lookup is argv0-relative; every harness runs the
# binary from a scratch path, so point it at the checkout (bin/build:52 does
# the same for the same reason).
mc_export_prelude() { export MANTICORE_PRELUDE="$1/prelude"; }

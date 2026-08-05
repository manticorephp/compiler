#!/usr/bin/env bash
#
# Differential test: manticore-compiled output vs the PHP interpreter.
#
#   tools/difftest.sh [path ...]      (default: tests/aot/cases/*.php)
#
# For each PHP file: run it under `php`, compile+run it under manticore, and
# diff stdout. This finds REAL divergences (a manticore bug, or a stale
# expected/ file) and measures language/stdlib parity beyond the curated
# expected outputs. Cases that `php` itself cannot run (manticore-only
# features — FFI stubs, #[Struct], compile-error fixtures) are auto-skipped as
# "php-incompat", not counted as failures.
#
# Buckets: MATCH (same stdout), DIFF (manticore != php), COMPILE (manticore
# failed to compile), TIMEOUT (either side hung), PHP-SKIP (php errored / can't
# run it). DIFF, COMPILE on a php-runnable file and TIMEOUT are real findings;
# they are listed at the end.

set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MANTICORE="$ROOT/bin/manticore"
[[ -x "$MANTICORE" ]] || { echo "fatal: bin/manticore missing; run bin/compile" >&2; exit 1; }
command -v php >/dev/null || { echo "fatal: php not found" >&2; exit 1; }

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

# Both sides get a deadline: an infinite loop in a case would otherwise hang the
# parity gate forever, and a php-side hang must be a finding rather than a quiet
# PHP-SKIP (which is what "php produced nothing" is classified as below).
# {@see tools/lib/limit.sh}
# shellcheck source=lib/limit.sh
source "$ROOT/tools/lib/limit.sh"
CASE_TIMEOUT="${MC_RUN_TIMEOUT:-60}"
COMPILE_TIMEOUT="${MC_COMPILE_TIMEOUT:-300}"
# The REFERENCE gets a bigger budget than our binary: a php case may legitimately
# sit on php's own default_socket_timeout, which is 60 s — enable_crypto.php spends
# exactly that failing a handshake on a plain socket. Bounding php at the same 60 s
# turns the oracle itself into a finding.
REF_TIMEOUT="${MC_REF_TIMEOUT:-180}"
timedout=0
TIMEDOUT=()

if [[ $# -gt 0 ]]; then
    FILES=("$@")
else
    FILES=(tests/aot/cases/*.php)
fi

# Known, understood divergences from PHP — excluded so the gate flags only
# NEW findings. Keep each with a one-line reason.
#   assoc_missing.php — typed-int assoc read of a missing key yields 0, not
#     null/"" (a typed-slot i64 can't carry null without boxing).
#   superglobals_env.php — $_ENV is populated; php's default variables_order
#     ("GPCS") leaves it empty. A native binary has no php.ini to flip.
#   array_erased_elem_repr_gap.php — the case EXISTS to print the divergence.
#     An `unknown` array element (and an aliased local) is decoded as a tagged
#     cell on read and stored raw on write, so `echo $a[0]` prints the address.
#     It also has no expected/ file, so the AOT suite SKIPs it rather than
#     blessing the wrong answer; here it would otherwise be permanent red.
is_known_divergence() {
    case "$1" in
        assoc_missing.php) return 0;;
        superglobals_env.php) return 0;;
        array_erased_elem_repr_gap.php) return 0;;
    esac
    return 1
}

match=0 diff=0 compile=0 phpskip=0
# Assigned EMPTY, not just declared: under `set -u` some bashes treat a declared-but-
# never-assigned array as unbound, so the summary below died with
# "COMPILES: unbound variable" on a run that had no compile failures — i.e. exactly
# when everything passed.
DIFFS=()
COMPILES=()

for f in "${FILES[@]}"; do
    [[ -f "$f" ]] || continue
    name="$(basename "$f")"

    # Reference: the PHP interpreter. A fatal/parse error (rc!=0 with no
    # stdout, or a stderr Fatal) means the file isn't plain runnable PHP.
    # A `<case>.diag` sidecar marks a case whose POINT is a diagnostic
    # (`#[\Deprecated]`, `#[\NoDiscard]`). Those need php's error output ON,
    # which the default invocation deliberately suppresses.
    if [[ -f "${f%.php}.diag" ]]; then
        ref="$(mc_limit "$REF_TIMEOUT" php -d error_reporting=E_ALL -d display_errors=STDOUT \
                   -d html_errors=0 -d log_errors=0 "$f" 2>"$WORK/ref.err")"; rrc=$?
    else
        ref="$(mc_limit "$REF_TIMEOUT" php -d error_reporting=0 -d display_errors=0 \
                   "$f" 2>"$WORK/ref.err")"; rrc=$?
    fi
    # 124 FIRST: a php side that ran out of time produces no stdout, which the
    # test below would otherwise read as "php cannot run this" and skip — hiding
    # the one case where the reference itself is the problem.
    if [[ $rrc -eq 124 ]]; then
        timedout=$((timedout + 1)); TIMEDOUT+=("$name (php)"); continue
    fi
    if [[ $rrc -ne 0 && -z "$ref" ]] || grep -qiE 'Parse error|Fatal error|Uncaught' "$WORK/ref.err"; then
        phpskip=$((phpskip + 1)); continue
    fi

    # Manticore: compile then run.
    crc=0
    mc_limit "$COMPILE_TIMEOUT" "$MANTICORE" compile "$f" -o "$WORK/bin" >"$WORK/c.err" 2>&1 || crc=$?
    if [[ $crc -ne 0 ]]; then
        if [[ $crc -eq 124 ]]; then
            timedout=$((timedout + 1)); TIMEDOUT+=("$name (compile)"); continue
        fi
        compile=$((compile + 1)); COMPILES+=("$name"); continue
    fi
    got="$(mc_limit "$CASE_TIMEOUT" "$WORK/bin" 2>/dev/null)"; grc=$?
    if [[ $grc -eq 124 ]]; then
        timedout=$((timedout + 1)); TIMEDOUT+=("$name"); continue
    fi

    # Same `<case>.sed` normaliser tests/aot/run.sh applies — diagnostics carry
    # the source path each side was handed, and those need not be spelled alike.
    if [[ -f "${f%.php}.sed" ]]; then
        ref="$(printf '%s\n' "$ref" | sed -f "${f%.php}.sed")"
        got="$(printf '%s\n' "$got" | sed -f "${f%.php}.sed")"
    fi

    if [[ "$got" == "$ref" ]]; then
        match=$((match + 1))
    elif is_known_divergence "$name"; then
        match=$((match + 1))   # documented limitation, not a regression
    else
        diff=$((diff + 1)); DIFFS+=("$name")
    fi
done

echo "════════ difftest vs PHP $(php -r 'echo PHP_VERSION;') ════════"
echo "  MATCH:     $match"
echo "  DIFF:      $diff"
echo "  COMPILE:   $compile   (manticore failed to compile a php-runnable file)"
echo "  TIMEOUT:   $timedout   (>${CASE_TIMEOUT}s ours / >${REF_TIMEOUT}s php — a hang, on either side)"
echo "  PHP-SKIP:  $phpskip   (not plain-runnable under php — manticore-only)"

if [[ ${#TIMEDOUT[@]} -gt 0 ]]; then
    echo "── timeouts ──"
    printf '  %s\n' "${TIMEDOUT[@]}"
fi
if [[ ${#DIFFS[@]} -gt 0 ]]; then
    echo "── output DIFFs (manticore != php) ──"
    printf '  %s\n' "${DIFFS[@]}"
fi
if [[ ${#COMPILES[@]} -gt 0 ]]; then
    echo "── compile failures (php-runnable) ──"
    printf '  %s\n' "${COMPILES[@]}"
fi

# Non-zero exit if there is any real finding, so this can gate. A timeout counts:
# it is either a liveness bug or a case that has no business being in the corpus.
[[ $diff -eq 0 && $compile -eq 0 && $timedout -eq 0 ]]

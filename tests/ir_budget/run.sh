#!/usr/bin/env bash
#
# IR-volume budget gate.
#
# The AOT suite proves the compiler is CORRECT; nothing proved it was not
# getting fatter. `echo "Hello, World!";` once emitted 203 function bodies and
# 323 KB of LLVM IR for 232 lines of user code, and no gate noticed, because
# the linker dead-strips the waste out of the binary — the cost is entirely in
# `clang -O2`, which docs/ROADMAP.md records as ~66% of `bin/build`.
#
# So this gate measures what the suite cannot: the IR a program emits.
#
# ── Ceilings, not goldens ──
# Each case records a MAXIMUM, not an exact figure. The preamble is not
# arch-identical (`__stdinp` vs `stdin`, kqueue vs epoll arms), so an exact
# golden would fail on Linux for reasons that have nothing to do with volume.
# A ceiling with headroom catches the thing worth catching: a change that makes
# the compiler emit materially more.
#
# Regenerate every ceiling after a deliberate improvement:
#     bash tests/ir_budget/run.sh --bless
#
#   bash tests/ir_budget/run.sh            check against tests/ir_budget/expected/
#   bash tests/ir_budget/run.sh --bless    rewrite the ceilings from this build
#
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
export MANTICORE_PRELUDE="$ROOT/prelude"

MANT="bin/manticore"
if [[ ! -x "$MANT" ]]; then
    echo "no $MANT — run bin/build first" >&2
    exit 2
fi

EXPECTED="tests/ir_budget/expected"
WORK="tests/ir_budget/.work"
mkdir -p "$EXPECTED" "$WORK"

BLESS=0
[[ "${1:-}" == "--bless" ]] && BLESS=1

# name|source. Chosen to span the shapes whose IR is driven by DIFFERENT terms:
# the fixed runtime preamble (hello), per-class dispatch (classes), the array
# runtime (arrays), and a whole prelude stack with a megamorphic dynamic call
# (http, async).
CASES=(
    "hello_world|tests/aot/cases/hello_world.php"
    "classes|tests/aot/cases/abstract_method_dispatch.php"
    "arrays|tests/aot/cases/array_access.php"
    "http_hello|examples/http/hello.php"
    "async_smoke|examples/async/smoke.php"
)

# Headroom over the measured figure when blessing. Small enough that a real
# regression trips it, wide enough that per-arch preamble drift does not.
HEADROOM_PCT=6

fail=0
pass=0
printf '%-14s %10s %10s   %6s %6s\n' CASE BYTES CEILING DEFS MAX
for entry in "${CASES[@]}"; do
    name="${entry%%|*}"
    src="${entry#*|}"
    if [[ ! -f "$src" ]]; then
        echo "MISSING $name ($src)"
        fail=$((fail + 1))
        continue
    fi
    log="$WORK/$name.stats"
    if ! MANTICORE_STATS=1 "$MANT" compile "$src" -o "$WORK/$name.bin" >"$log" 2>&1; then
        echo "COMPILE-FAIL $name — see $log"
        fail=$((fail + 1))
        continue
    fi
    # `IR: pruned <dropped> of <total> defs, <bytes> bytes` is the last word on
    # what clang is handed; the earlier `IR: bodies/preamble` lines are the
    # pre-prune figures and would over-report.
    # "stats: 140ms  IR: pruned 164 of 203 defs, 99062 bytes"
    #  $1     $2     $3  $4     $5  $6 $7  $8     $9    $10
    # "stats: 134ms  IR: bodies 117845 bytes"
    #  $1     $2     $3  $4     $5      $6
    read -r bytes defs < <(awk '
        /IR: pruned/   { dropped = $5; total = $7; b = $9 }
        /IR: bodies/   { bodies = $5 }
        /IR: preamble/ { pre = $5 }
        END {
            if (b == "") { b = bodies + pre; kept = 0 } else { kept = total - dropped }
            print b, kept
        }
    ' "$log")
    if [[ -z "${bytes:-}" || "$bytes" == "0" ]]; then
        echo "NO-STATS $name — see $log"
        fail=$((fail + 1))
        continue
    fi
    exp="$EXPECTED/$name.budget"
    if [[ $BLESS -eq 1 || ! -f "$exp" ]]; then
        maxb=$(( bytes + bytes * HEADROOM_PCT / 100 ))
        maxd=$(( defs + defs * HEADROOM_PCT / 100 ))
        {
            echo "# ceiling for $src — regenerate with tests/ir_budget/run.sh --bless"
            echo "max_bytes=$maxb"
            echo "max_defs=$maxd"
        } >"$exp"
        printf '%-14s %10s %10s   %6s %6s  BLESSED\n' "$name" "$bytes" "$maxb" "$defs" "$maxd"
        pass=$((pass + 1))
        continue
    fi
    maxb=$(awk -F= '/^max_bytes=/ { print $2 }' "$exp")
    maxd=$(awk -F= '/^max_defs=/ { print $2 }' "$exp")
    status=OK
    if (( bytes > maxb )); then status=OVER-BYTES; fi
    if (( defs > maxd )); then status=OVER-DEFS; fi
    printf '%-14s %10s %10s   %6s %6s  %s\n' "$name" "$bytes" "$maxb" "$defs" "$maxd" "$status"
    if [[ "$status" == OK ]]; then pass=$((pass + 1)); else fail=$((fail + 1)); fi
done

echo
echo "ir budget: $pass ok, $fail over"
[[ $fail -eq 0 ]]

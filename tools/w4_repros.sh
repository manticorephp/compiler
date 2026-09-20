#!/usr/bin/env bash
# W4 (self-describing value channels) exit gate: every open producer repro under
# tests/aot/repro/w4/ compiled with bin/manticore and diffed against the php
# oracle output recorded next to it (<name>.expected). A repro that passes here
# is PROMOTED: `git mv` its .php into tests/aot/cases/ and its .expected into
# tests/aot/expected/<name>.out, so the suite guards it from then on.
#
# Exit 0 only when every repro still listed passes. Not part of run.sh — the
# suite must stay green while these are open. See docs/design/value-channels.md.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
WORK="${W4_WORK:-tests/aot/.work/w4}"
mkdir -p "$WORK"
pass=0; fail=0
for src in tests/aot/repro/w4/*.php; do
    name="$(basename "$src" .php)"
    exp="tests/aot/repro/w4/$name.expected"
    bin="$WORK/$name.bin"
    rm -f "$bin"
    if ! bin/manticore compile "$src" -o "$bin" > "$WORK/$name.cc.log" 2>&1; then
        echo "FAIL $name (compile, see $WORK/$name.cc.log)"; fail=$((fail+1)); continue
    fi
    "$bin" > "$WORK/$name.out" 2>&1
    rc=$?
    if cmp -s "$WORK/$name.out" "$exp"; then
        echo "PASS $name  (promote it)"; pass=$((pass+1))
    else
        echo "FAIL $name (rc=$rc)"; fail=$((fail+1))
        diff "$exp" "$WORK/$name.out" | head -${W4_DIFF_LINES:-8} | sed 's/^/    /'
    fi
done
echo "---"
echo "w4 repros: pass=$pass fail=$fail"
[[ $fail -eq 0 ]]

#!/usr/bin/env bash
# Gated compile-time type checker (MANTICORE_TYPECHECK=1) regression guard.
# Asserts the checker stays CONSERVATIVE: zero false positives on the corpus
# and on the compiler's own source (self-host), while still catching a real
# array↔scalar misuse. Run after touching src/Compile/Mir/Passes/TypeCheck.php.
set -u
cd "$(dirname "$0")/.."

fail=0

echo "── self-host source (expect 0 type errors) ──"
MANTICORE_TYPECHECK=1 bin/manticore build manticore.json > /tmp/tcscan_self.log 2>&1
n=$(grep -ci "type error" /tmp/tcscan_self.log)
echo "self-host type errors: $n"
[ "$n" = "0" ] || { echo "FAIL: self-host false positives"; grep -i "type error" /tmp/tcscan_self.log; fail=1; }

echo "── corpus (expect 0 type errors) ──"
c=0
for f in tests/aot/cases/*.php; do
    out=$(MANTICORE_TYPECHECK=1 bin/manticore compile "$f" -o /tmp/tcscan_out 2>&1)
    if echo "$out" | grep -qi "type error"; then
        echo "FAIL: flagged $f"; echo "$out" | grep -i "type error" | head -2; c=$((c+1))
    fi
done
echo "corpus flagged: $c"
[ "$c" = "0" ] || fail=1

echo "── positive: array passed to an int param (expect a type error) ──"
cat > /tmp/tcscan_bad.php <<'PHP'
<?php
function g(int $x): int { return $x + 1; }
$a = [1, 2, 3];
echo g($a), "\n";
PHP
if MANTICORE_TYPECHECK=1 bin/manticore compile /tmp/tcscan_bad.php -o /tmp/tcscan_bad 2>&1 | grep -qi "type error"; then
    echo "ok: real misuse caught"
else
    echo "FAIL: did not catch array→int"; fail=1
fi

[ "$fail" = "0" ] && echo "TYPECHECK SCAN OK" || { echo "TYPECHECK SCAN FAILED"; exit 1; }

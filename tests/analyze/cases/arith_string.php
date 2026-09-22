<?php
// Arithmetic on a string operand, at the severity php's own behaviour justifies.
// php is the oracle for every line here (`php -d xdebug.mode=off`).

// Fully numeric literals: php computes, we compute, nothing is reported.
echo "5" - 1, "\n";            // 4
echo "2026" + "06", "\n";      // 2032
echo " 5" + 1, "\n";           // 6   — leading whitespace is part of the number
echo "5 " + 1, "\n";           // 6   — and so is trailing whitespace (php 8)
echo "1e3" * 2, "\n";          // 2000
echo "-3" + ".5", "\n";        // -2.5

// A numeric prefix with trailing text: php warns and computes on the prefix.
echo "12abc" + 1, "\n";        // Warning + 13
echo "5 x" + 1, "\n";          // Warning + 6

echo "1e" + 1, "\n";           // Warning + 2 — a bare exponent is not part of the number

// No numeric prefix at all: php raises a TypeError, so the line cannot run.
echo "abc" - 1, "\n";
echo "" + 1, "\n";

// A string whose VALUE is a run-time fact: a hazard, not a defect.
function fromParam(string $label): void { echo $label - 1, "\n"; }
function fromNarrowing(mixed $m): void { if (is_string($m)) { echo $m * 2, "\n"; } }

// The string operator is not arithmetic.
echo "a" . "b", "\n";

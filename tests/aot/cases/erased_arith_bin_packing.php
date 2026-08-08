<?php
// The driver from docs/design/unknown-cell-soundness.md §17.3, and the exact
// shape Compile\Mir\SplitModule hand-rolls a counter loop to avoid: fill a
// counter array, then read-compare-write it. Every write is an erased sum
// stored back into a cell element.
function pick(int $parts): int
{
    $load = array_fill(0, $parts, 0);
    foreach ([353307, 70000, 53000] as $sz) {
        $min = 0;
        for ($p = 1; $p < $parts; $p = $p + 1) {
            if ($load[$p] < $load[$min]) { $min = $p; }
        }
        $load[$min] = $load[$min] + $sz;
    }
    return $load[0];
}

echo pick(8), "\n";
echo pick(2), "\n";
echo pick(1), "\n";

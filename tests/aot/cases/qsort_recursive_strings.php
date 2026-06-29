<?php
// A recursive by-ref quicksort over a STRING array. The recursive self-call
// `qsort($a,...)` forwards its own bare-`array` param; that unknown-elem arg
// must NOT poison the call-site element inference (top-level passes string[]).
// Before the fix the param erased to vec[int], so `$a[$j] < $pivot` compiled as
// an integer compare on string POINTERS — sort produced garbage / no-op.
function qsort(array &$a, int $lo, int $hi): void {
    if ($lo >= $hi) return;
    $pivot = $a[$hi]; $i = $lo - 1;
    for ($j = $lo; $j < $hi; $j++) {
        if ($a[$j] < $pivot) { $i++; $t = $a[$i]; $a[$i] = $a[$j]; $a[$j] = $t; }
    }
    $i++; $t = $a[$i]; $a[$i] = $a[$hi]; $a[$hi] = $t;
    qsort($a, $lo, $i - 1); qsort($a, $i + 1, $hi);
}
$a = ["pear", "fig", "kiwi", "apple", "zebra", "mango", "date", "lime"];
qsort($a, 0, count($a) - 1);
foreach ($a as $x) { echo $x, "\n"; }

<?php
// unset_churn — big map, interleaved unset + lookup + re-insert.
// Exercises: unset memmove-tail compaction + index_drop → O(n) rebuild churn.
$m = [];
for ($i = 0; $i < 10000; $i++) { $m["k" . $i] = $i; }
$sum = 0;
$reps = 5 * $argc;
for ($r = 0; $r < $reps; $r++) {
    for ($i = 0; $i < 10000; $i += 4) {
        unset($m["k" . $i]);
        $j = ($i + 1) % 10000;
        if (isset($m["k" . $j])) { $sum += $m["k" . $j]; }
        $m["k" . $i] = $i;
    }
}
echo $sum, " ", count($m), "\n";

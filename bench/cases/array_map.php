<?php
// array_map with an arrow closure over an int vec. Sizes seeded from $argc so
// the source vec is runtime-sized and the map can't be constant-folded.
$len = 1000 * $argc;
$src = [];
for ($i = 0; $i < $len; $i++) { $src[] = $i; }
$sum = 0;
$reps = 20000 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $out = array_map(fn($x) => $x * 2 + 1, $src);
    $sum += $out[$len - 1];
}
echo $sum, "\n";

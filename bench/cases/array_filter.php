<?php
// array_filter with an arrow predicate (preserves keys -> sparse-int build).
// Sizes seeded from $argc so the build is runtime-sized, not folded.
$len = 1000 * $argc;
$src = [];
for ($i = 0; $i < $len; $i++) { $src[] = $i; }
$sum = 0;
$reps = 20000 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $out = array_filter($src, fn($x) => ($x & 1) === 0);
    $sum += \count($out);
}
echo $sum, "\n";

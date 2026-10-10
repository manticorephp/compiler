<?php
// in_array_int — in_array over an int vec (linear scan, strict and loose). Reps seeded from $argc.
$h = [];
for ($i = 0; $i < 1000; $i++) { $h[] = $i * 3; }
$hits = 0;
$reps = 100000 * $argc;
for ($r = 0; $r < $reps; $r++) {
    if (in_array(($r * 7) % 4000, $h, true)) { $hits++; }
    if (in_array(($r * 5) % 4000, $h)) { $hits++; }
}
echo $hits, "\n";

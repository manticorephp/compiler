<?php
// in_array over a string vec (linear scan). Reps seeded from $argc.
$h = [];
for ($i = 0; $i < 200; $i++) { $h[] = "item" . $i; }
$hits = 0;
$reps = 200000 * $argc;
for ($r = 0; $r < $reps; $r++) {
    if (in_array("item" . ($r % 250), $h, true)) { $hits++; }
}
echo $hits, "\n";

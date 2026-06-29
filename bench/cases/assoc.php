<?php
// String-keyed assoc build + lookup (hashed map path). Reps seeded from $argc.
$sum = 0;
$reps = 6000 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $m = [];
    for ($i = 0; $i < 200; $i++) { $m["key" . $i] = $i * 3; }
    for ($i = 0; $i < 200; $i++) { $sum += $m["key" . $i]; }
}
echo $sum, "\n";

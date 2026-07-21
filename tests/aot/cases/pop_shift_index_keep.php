<?php
// pop/shift on a hashed map with a live index, then more lookups/inserts —
// the surgical index-repair path (backshift + sweep) must keep every
// remaining key reachable after each removal.
$m = [];
for ($i = 0; $i < 24; $i++) { $m["p" . $i] = $i + 1; }
$t = 0;
for ($i = 0; $i < 24; $i++) { $t += $m["p" . $i]; }
echo "warm=", $t, "\n";

for ($r = 0; $r < 6; $r++) {
    $v = array_pop($m);
    $sum = 0;
    foreach ($m as $k => $x) { $sum += $m[$k]; }
    echo "pop=", $v, " sum=", $sum, " n=", count($m), "\n";
}
for ($r = 0; $r < 6; $r++) {
    $v = array_shift($m);
    $sum = 0;
    $seen = 0;
    for ($i = 0; $i < 24; $i++) {
        if (isset($m["p" . $i])) { $sum += $m["p" . $i]; $seen++; }
    }
    echo "shift=", $v, " sum=", $sum, " seen=", $seen, "\n";
}
$m["new1"] = 100;
$m["new2"] = 200;
$s = 0;
for ($i = 0; $i < 24; $i++) { $s += isset($m["p" . $i]) ? $m["p" . $i] : 0; }
echo "tail=", $s + $m["new1"] + $m["new2"], " n=", count($m), "\n";

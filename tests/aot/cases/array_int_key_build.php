<?php
// Exercises the hashed int-key insert fast-append (idx >= next_int) AND its
// fallbacks: update an existing key (idx < next_int, hit) and fill a gap
// (idx < next_int, miss). Guards the next_int maintenance.
function dump($a) { $p = []; foreach ($a as $k => $v) { $p[] = $k . "=" . $v; } echo implode(",", $p), "\n"; }

$o = [];
$o[1] = 10; $o[3] = 30; $o[5] = 50;   // increasing -> fast append, hashed
dump($o);
$o[3] = 99;                            // update existing (idx < next_int, hit)
$o[2] = 20;                            // gap fill   (idx < next_int, miss)
dump($o);
echo $o[1], ",", $o[2], ",", $o[3], ",", $o[5], ",", count($o), "\n";

// large increasing build (the array_filter hot path) — keys preserved
$big = [];
for ($i = 0; $i < 500; $i++) { $big[$i * 2] = $i; }
echo count($big), ",", $big[0], ",", $big[998], "\n";
$sum = 0; foreach ($big as $k => $v) { $sum += $k; } echo $sum, "\n";

// array_filter result keys preserved at scale
$src = range(1, 100);
$ev = array_filter($src, fn($x) => $x % 3 === 0);
echo count($ev), ",", $ev[2], ",", $ev[98], "\n";   // keys 2,5,...98 -> values 3,6,...99

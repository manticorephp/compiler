<?php
// Append past several power-of-two capacity boundaries and read elements
// back. Geometric capacity must never corrupt or drop an element: the
// realloc no-ops between boundaries, and relocates on crossing one.
$a = [];
for ($i = 0; $i < 100; $i = $i + 1) { $a[] = $i * 2; }
echo count($a), "\n";
echo $a[0], " ", $a[1], " ", $a[4], " ", $a[5], " ", $a[8], " ", $a[63], " ", $a[64], " ", $a[99], "\n";
$sum = 0;
foreach ($a as $v) { $sum = $sum + $v; }
echo $sum, "\n";

// String elements across a relocation, value-semantics intact.
$s = [];
for ($j = 0; $j < 20; $j = $j + 1) { $s[] = "n" . $j; }
echo $s[0], " ", $s[7], " ", $s[19], " ", count($s), "\n";

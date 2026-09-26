<?php
$a = [1, 'x'];
$r = &$a[0];
for ($i = 0; $i < 100; $i++) { $a[] = $i; }
$r = 555;
echo $a[0], ' ', count($a), "\n";
$b = ['x' => 1, 'y' => 'z'];
$q = &$b['x'];
for ($i = 0; $i < 100; $i++) { $b["k$i"] = $i; }
$q = 777;
echo $b['x'], "\n";
$c = [];
$p = &$c[];
for ($i = 0; $i < 100; $i++) { $c[] = "s$i"; }
$p = 'first';
echo $c[0], ' ', $c[100], "\n";
function inner(): array { $l = ['k' => [0, 'm']]; $w = &$l['k'][1]; $w = 'M'; $l['k'][] = 2; $w .= '!'; return $l; }
echo json_encode(inner()), "\n";

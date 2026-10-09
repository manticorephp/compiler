<?php
use Manticore\Ds\Map;

$m = new Map();
for ($i = 0; $i < 6; $i++) { $m->set("k$i", $i); }
foreach ($m as $k => $v) { if ($v % 2 === 0) { $m->remove($k); } }   // removing the current key is fine
echo implode(',', array_keys($m->toArray())), "\n";
$big = new Map();
for ($i = 0; $i < 64; $i++) { $big->set($i, $i); }
for ($i = 0; $i < 60; $i++) { $big->remove($i); }
try {
    foreach ($big as $k => $v) { $big->set(1000 + $k, 0); }        // put compacts -> epoch moves
} catch (RuntimeException $e) { echo $e->getMessage(), "\n"; }
echo count($big), "\n";

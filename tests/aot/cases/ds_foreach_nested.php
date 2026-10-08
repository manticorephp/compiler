<?php
use Manticore\Ds\Map;

$m = new Map(); foreach (['a' => 1, 'b' => 2, 'c' => 3] as $k => $v) { $m->set($k, $v); }
foreach ($m as $k1 => $v1) { foreach ($m as $k2 => $v2) { echo "$k1$k2=", $v1 * $v2, ' '; } } echo "\n";
$once = false;
try {
    foreach ($m as $k1 => $v1) {
        foreach ($m as $k2 => $v2) {
            if (!$once) { $once = true; $m->set('new', 1); }
            echo "$k1$k2 ";
        }
    }
    echo "\n";
} catch (RuntimeException $e) { echo "\n", $e->getMessage(), "\n"; }
echo count($m), "\n";
$big = new Map();
for ($i = 0; $i < 64; $i++) { $big->set($i, $i); }
for ($i = 0; $i < 60; $i++) { $big->remove($i); }
try {
    foreach ($big as $k1 => $v1) { foreach ($big as $k2 => $v2) { $big->set(1000 + $k2, 0); } }
} catch (RuntimeException $e) { echo $e->getMessage(), "\n"; }
echo count($big), "\n";

<?php
use Manticore\Ds\Map;

$m = new Map();
$m[1] = 'int'; $m->set('1', 'str'); $m[''] = 'empty'; $m['01'] = 'zero-one';
echo count($m), "\n";
foreach ($m as $k => $v) { var_dump($k); echo $v, "\n"; }
foreach ([1.0, true, null, [1]] as $bad) {
    try { $m[$bad] = 1; } catch (Throwable $e) { echo $e->getMessage(), "\n"; }
}
try { $m->has(2.5); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
$m[PHP_INT_MIN] = 'min'; $m[PHP_INT_MAX] = 'max';
echo $m[PHP_INT_MIN], ' ', $m[PHP_INT_MAX], "\n";

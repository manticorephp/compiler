<?php
// A function returning its own array parameter: the caller may store the result over the variable it passed.
function id(array $v) { return $v; }
$fn = static function (array $v) { if (!isset($v['url'])) { return $v; } return $v; };
$value = ['url' => 's', 'logging' => true];
foreach ([$fn] as $c) { $value = $c($value); }
echo json_encode($value), "\n";
$value = ['a' => 'x', 'b' => false];
foreach (['id'] as $c) { $value = $c($value); }
echo json_encode($value), "\n";
$value = ['k' => 1, 'f' => true];
foreach ([$fn, $fn, 'id'] as $c) { $value = $c($value); }
echo json_encode($value), "\n";
class K { public function same(array $v) { return $v; } }
$k = new K(); $value = ['m' => true, 'n' => 'z'];
foreach (['same', 'same'] as $m) { $value = $k->$m($value); }
echo json_encode($value), "\n";

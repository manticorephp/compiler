<?php
final class Out { public function on(): void {} }
$ls = [["a", "on"]]; foreach ($ls as $k => &$v) { unset($ls[$k]); } unset($v); var_dump($ls);
$a = [[1, 2], [3, 4]]; foreach ($a as $p => &$l) { foreach ($l as $k => &$w) { if ($w % 2) { unset($l[$k]); } else { $w *= 10; } } unset($w); } unset($l); var_dump($a);
$m = ['x' => 1, 'y' => 2, 'z' => 3]; foreach ($m as $k => &$v) { if ($k === 'y') { unset($m['y']); } else { $v++; } } unset($v); var_dump($m);
$n = [1, 2, 3]; foreach ($n as $k => &$v) { $v *= 2; if ($k === 0) { unset($n[2]); } } unset($v); var_dump($n);
$q = [1, 2]; foreach ($q as &$v) { if ($v === 1) { $q[] = 9; } $v += 100; } unset($v); var_dump($q);
$d = new stdClass(); $e = [[$d, 'x']]; foreach ($e as $k => &$v) { if ($v === [$d, 'x']) { unset($e[$k]); } } unset($v); var_dump(count($e));

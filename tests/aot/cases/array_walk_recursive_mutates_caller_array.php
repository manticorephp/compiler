<?php
class C { public function f(array $a) { array_walk_recursive($a, static function (&$item) { $item = 'S'; }); return $a; } }
$d = ['a' => 'x', 'c' => ['e' => 'f']];
(new C)->f($d);
echo json_encode($d), "\n";

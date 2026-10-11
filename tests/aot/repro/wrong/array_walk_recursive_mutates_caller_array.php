<?php
// array_walk_recursive on a by-value array param mutates the caller's array
// issue: #178
class C { public function f(array $a) { array_walk_recursive($a, static function (&$item) { $item = 'S'; }); return $a; } }
$d = ['a' => 'x', 'c' => ['e' => 'f']];
(new C)->f($d);
echo json_encode($d), "\n";

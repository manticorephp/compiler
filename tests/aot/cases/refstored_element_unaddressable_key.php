<?php
// An element handed to a reference-storing param whose key or base has no address falls back to a value; the compiler used to crash on it.
class B { public $k = []; public function keep(&$x) { $this->k[] = &$x; } }
function gen() { yield 1; }
$b = new B;
$c = ['a' => [1, 2], 'b' => 3];
$f = 1.5;
$arr = [1 => 'one'];
$b->keep($arr[$f]);
$call = [$b, 'keep'];
$call($c['a'][0]);
$m = 'keep';
$b->$m($c['a'][1]);
$fn = function (&$r) use ($b) { $b->k[] = &$r; };
$fn($c['b']);
function mk(): array { return ['z' => 1]; }
$obj = new stdClass; $obj->p = ['q' => [1]];
$b->keep($obj->p['q'][0]);
$c['a'][0] = 'w'; $c['b'] = 'x';
echo count($b->k), json_encode($c), json_encode($obj->p), "\n";

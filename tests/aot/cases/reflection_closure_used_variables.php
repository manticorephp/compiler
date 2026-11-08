<?php
class K { public $p = 7; public function mk() { $q = 'qq'; return function () use ($q) { return $this->p . $q; }; } }
$a = 5; $b = 'str'; $c = [1, 2]; $d = 1.5; $e = true; $o = new K; $n = null;
$f = function ($x) use ($a, $b, $c, $d, $e, $o) { return $a; };
$r = new ReflectionFunction($f);
var_dump($r->getClosureUsedVariables() == ['a' => 5, 'b' => 'str', 'c' => [1, 2], 'd' => 1.5, 'e' => true, 'o' => $o]);
echo json_encode(array_keys($r->getClosureUsedVariables())), "\n";
$g = fn($z) => $z + $a + $d;
echo json_encode((new ReflectionFunction($g))->getClosureUsedVariables()), "\n";
echo json_encode((new ReflectionFunction($o->mk()))->getClosureUsedVariables()), "\n";
$h = function () {};
echo json_encode((new ReflectionFunction($h))->getClosureUsedVariables()), "\n";

<?php
class A { public $v = 5; public ?Closure $f = null; }
function id(mixed $x): mixed { return $x; }
$fn = function ($a, $b = 1) { return $a + $b; };
$th = function ($a) { return $a + $this->v; };
$c = id($fn);
echo $c->__invoke(1, 2), "\n";
echo id($th)->call(new A, 10), "\n";
$b = id($th)->bindTo(new A);
echo $b(1), "\n";
$arr = ['k' => $fn];
echo $arr['k']->__invoke(3), "\n";
echo $arr['k']?->__invoke(4), "\n";
$a = new A; $a->f = $fn;
echo $a->f->__invoke(7), "\n";
var_dump(id(null)?->__invoke(1));
foreach ([id($fn), id(new A), id(null), id(3)] as $r) {
    try { $r->nope(); } catch (Error $e) { echo $e->getMessage(), "\n"; }
}
try { id(new A)->__invoke(1); } catch (Error $e) { echo $e->getMessage(), "\n"; }

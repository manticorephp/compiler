<?php
class A { public function foo() {} public static function bar() {} }
function keep(&$r) { static $s = []; $s[] = &$r; }
$m = new ReflectionMethod('A', 'foo');
keep($m->name);
echo $m->getName(), ' ', $m->isStatic() ? 's' : 'i', ' ', $m->getModifiers(), "\n";
foreach ((new ReflectionClass('A'))->getMethods() as $x) { keep($x->name); echo $x->name, ' ', $x->isStatic() ? 's' : 'i', "\n"; }

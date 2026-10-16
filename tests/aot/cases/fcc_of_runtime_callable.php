<?php
// `$c(...)` of a runtime callable builds a Closure; an invokable object's is its `__invoke` closure.
final class Ctl { public function __invoke(string $name, int $n = 2): string { return str_repeat($name, $n); } }
final class Svc { public function run(string $x): string { return "run:$x"; } public static function st(int $a): int { return $a * 3; } }
function ids(mixed $v): mixed { return $v; }
function fcc(callable $c): \Closure { return $c(...); }
$f = fcc(ids(new Ctl()));
var_dump($f instanceof \Closure, $f('ab'), $f('x', 3));
$r = new \ReflectionFunction($f);
foreach ($r->getParameters() as $p) { echo $p->getName(), $p->isOptional() ? '?' : '', ' '; }
echo "\n";
$g = fcc(ids([new Svc(), 'run']));
var_dump($g instanceof \Closure, $g('y'));
$h = fcc(ids([Svc::class, 'st']));
var_dump($h(5));
$k = fcc(ids(fn (int $z) => $z + 1));
var_dump($k(1));

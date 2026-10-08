<?php
// new ReflectionFunction($closure) throws "Function () does not exist" instead of reflecting the closure.
$f = function (int $a, string $b = 'x'): int { return $a; };
$r = new ReflectionFunction($f);
echo $r->getNumberOfParameters(), "\n";
echo $r->getParameters()[1]->getName(), "\n";
echo $r->invoke(7), "\n";
var_dump($r->getClosureThis());

<?php
// ReflectionMethod::invoke and getClosure throw "not invokable" for a method with a variadic or by-reference parameter.
// issue: #171
class Sum
{
    public function add(int $x, int ...$more): int { return $x + array_sum($more); }
    public function push(array &$into, int $v): void { $into[] = $v; }
}
$m = new ReflectionMethod(Sum::class, 'add');
echo $m->invoke(new Sum(), 1, 2, 3), "\n";
echo $m->getClosure(new Sum())(1, 2, 3), "\n";
$a = [];
(new ReflectionMethod(Sum::class, 'push'))->invokeArgs(new Sum(), [&$a, 5]);
echo count($a), "\n";

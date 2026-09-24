<?php
// A string cell handed to an `int` parameter coerces like php (symfony Finder
// passes its NumberComparator target `'1'` as `int $minDepth`): it read the
// string's address.
final class T { private $t; public function __construct($t) { $this->t = $t; } public function getTarget() { return $this->t; } }
final class D { public function __construct(int $min = 0, int $max = \PHP_INT_MAX) { var_dump($min, $max); } }
function f(array $cs): void {
    $minDepth = 0;
    $maxDepth = \PHP_INT_MAX;
    foreach ($cs as $c) { $minDepth = $maxDepth = $c->getTarget(); }
    if ($minDepth > 0 || $maxDepth < \PHP_INT_MAX) { new D($minDepth, $maxDepth); }
}
f([new T('1')]);
function g(int $x): int { return $x + 1; }
$s = (new T('41'))->getTarget();
var_dump(g($s));

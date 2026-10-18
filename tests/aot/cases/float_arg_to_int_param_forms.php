<?php
function f(int $n): int { return $n; }
$n = 1000;
echo f($n / 2), f(10.0), f(2.7), "\n";
$g = fn(int $x) => $x + 1;
echo $g($n / 4), "\n";
class K { function m(int $a) { return $a * 2; } static function s(int $a) { return $a; } function __construct(public int $p = 0) {} }
echo (new K($n / 8))->p, (new K)->m(3.0), K::s(4.0), "\n";

<?php
function pick(bool $b): int|float { return $b ? 3 : 2.5; }
echo pick(true), "\n";
echo pick(false), "\n";
function f(int $n): int|string { return $n > 0 ? $n : "neg"; }
echo f(5), "\n";
echo f(-1), "\n";
$y = false ? 10 : "ten";
echo $y, "\n";
function g(bool $b) { return $b ? 2 : 3.5; }
var_dump(g(false));
var_dump(g(true));
echo (1 > 0 ? "a" : 1) . (0 > 1 ? "b" : 2.0), "\n";

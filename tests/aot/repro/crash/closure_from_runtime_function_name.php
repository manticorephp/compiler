<?php
// Closure::fromCallable with a function name only known at run time returns a closure that crashes when called.
// issue: #170
function h2(int $a): int { return $a + 1; }
function mk(string $name): Closure { return Closure::fromCallable($name); }
$c = mk(strrev('2h'));
echo $c(3), "\n";

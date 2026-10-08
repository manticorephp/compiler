<?php
// A spread into a dynamic closure pads the parameters it leaves out with their defaults.
function run(Closure $f, array $a) { return $f(...$a); }
echo run(fn(int $x = 3) => $x + 1, []), "\n";
echo run(fn(int $x = 3, $y = 'k') => $x . $y, [9]), "\n";

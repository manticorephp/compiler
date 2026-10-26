<?php
function run(Closure $f, array $a, array $b) { return $f(...$a, ...$b); }
echo run(fn($x, $y, $z, $w) => "$x-$y-$z-$w", [1, 2], [3, 4]), "\n";
echo run(fn($x) => "[$x]", [1], []), "\n";

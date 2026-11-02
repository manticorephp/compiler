<?php
function f(array $a, array $b) { return count($a + $b); }
echo f([], ['k' => 'v', 'j' => 'w']), "\n";
function g(array $a, array $b) { return $a + $b; }
foreach (g([1, 2], [5, 6, 7, 'x' => 'y']) as $k => $v) { echo $k, '=', $v, ' '; }
echo "\n";
echo count(g(['a' => 1], ['a' => 2, 'b' => 3])), "\n";

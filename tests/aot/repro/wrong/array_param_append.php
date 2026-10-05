<?php
// A by-value `array $a` param appended in the callee: crash / raw pointer / caller's array mutated
// issue: #27
function g(array $a): int { $a[] = 1; return count($a); }
function h(array $a): void { $a[] = 1; echo json_encode($a), "\n"; }
echo g(['a', 'b']), "\n";
h(['k' => 'v']);
$f = function (array $a): int { $a[] = 9; return count($a); };
$x = [1, 2];
$f($x);
echo json_encode($x), "\n";

<?php
// Spreading a mixed[] into a method call with fixed params before a variadic param segfaults.
// issue: #186
class Sum { public function add(int $x, int ...$more): int { return $x + array_sum($more); } }
/** @param mixed[] $a */
function tr(Sum $t, array $a): mixed { return $t->add(...$a); }
echo tr(new Sum(), [1, 2, 3]), "\n";

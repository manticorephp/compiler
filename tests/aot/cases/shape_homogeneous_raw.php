<?php
/** @param array{0:int,1:int} $p */
function sum(array $p): int { return $p[0] + $p[1]; }
/** @param array{x: float, y: float} $pt */
function len(array $pt): float { return sqrt($pt['x'] * $pt['x'] + $pt['y'] * $pt['y']); }
echo sum([3, 4]), ' ', len(['x' => 3.0, 'y' => 4.0]), "\n";

<?php
// A function called by a run-time name with a string-keyed spread pack binds the values by position, not by parameter name.
function named(int $x, int $y): string { return "x=$x y=$y"; }
/** @param array<string, mixed> $a */
function callA(string $f, array $a): mixed { return $f(...$a); }
var_dump(callA('named', ['y' => 3, 'x' => 1]));

<?php
// A function called by a run-time name with a spread pack shorter than its parameter list ignores defaults and drops variadic arguments.
// issue: #639
function joinS(string ...$s): string { return implode('|', $s); }
function pad(string $s, int $n = 4, string $with = '.'): string { return str_pad($s, $n, $with); }
function callM(string $f, mixed ...$a): mixed { return $f(...$a); }
var_dump(callM('joinS', 'a', 'b', 'c'));
var_dump(callM('pad', 'x'));
var_dump(callM('str_pad', 'x', 3));

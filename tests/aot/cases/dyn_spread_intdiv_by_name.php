<?php
// intdiv() called by a run-time name with a spread pack throws "Call to undefined function intdiv()".
function callI(string $f, int ...$a): mixed { return $f(...$a); }
$n = 'intdiv';
var_dump(callI($n, 9, 2));

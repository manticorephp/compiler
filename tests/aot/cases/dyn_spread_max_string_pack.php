<?php
// max() called by a run-time name with a string[] spread pack returns the first element, not the largest.
function callS(string $f, string ...$a): mixed { return $f(...$a); }
$n = 'max';
var_dump(callS($n, 'a', 'b'));

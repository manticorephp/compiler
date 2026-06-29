<?php
// untyped functions whose every return agrees on one scalar kind:
// the call expression takes the concrete type (echo derefs a string, not %d).
function greet($n) { return "Hello " . $n; }
echo greet("Ada"), "\n";
echo greet("Bob"), "\n";

function dbl($x) { return $x * 2; }       // int
echo dbl(21), "\n";
var_dump(dbl(21));

function isPos($x) { return $x > 0; }      // bool
var_dump(isPos(3));
var_dump(isPos(-1));

// chained: outer returns inner()'s string
function inner($s) { return "[" . $s . "]"; }
function outer($s) { return inner($s); }
echo outer("x"), "\n";

// string concat of a call result
echo "got=" . greet("Z") . "\n";

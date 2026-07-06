<?php
// A `global $g` read in a scope that never stores to it must carry the real
// (string/object/array) type across scopes, not the hard-lowered int — and an
// array global mutated in one function is visible everywhere.
$name = "hello";
$arr = [1, 2, 3];
$obj = null;

class Box { public int $v = 0; }

function greet() { global $name; return $name . "!"; }        // pure read
function setName() { global $name; $name = "world"; }          // store
function push() { global $arr; $arr[] = 99; return count($arr); }
function sumArr() { global $arr; $s = 0; foreach ($arr as $x) { $s += $x; } return $s; }
function mkObj() { global $obj; $obj = new Box(); $obj->v = 42; }
function readObj() { global $obj; return $obj->v; }

echo greet(), "\n";
setName();
echo greet(), "\n";
echo $name, "\n";

echo push(), "\n";
echo sumArr(), "\n";

mkObj();
echo readObj(), "\n";

// rebind a string global in a loop — the old buffer must not be freed under
// the global (no use-after-free).
for ($i = 0; $i < 100; $i++) { $t = "s" . strrev("cba"); $name = $t; }
echo greet(), "\n";

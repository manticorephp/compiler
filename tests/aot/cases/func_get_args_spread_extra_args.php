<?php
// func_get_args() drops spread arguments beyond the declared parameters
class O { function z() { return func_get_args(); } }
function f() { return func_get_args(); }
$o = new O();
echo implode(",", $o->z(...[3, 4])), "\n";
echo implode(",", f(...[5, 6])), "\n";
$a = [7, 8];
echo implode(",", $o->z(1, ...$a)), "\n";

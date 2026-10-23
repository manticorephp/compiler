<?php
// A builtin function named by a runtime string is called as if undefined: $f("b"), $f(...) and call_user_func($f) all answer 0 or empty.
function ids(mixed $v): mixed { return $v; }
$f = ids("strtoupper");
echo $f("b"), "\n";
$g = $f(...);
echo $g("c"), "\n";
echo call_user_func($f, "d"), "\n";

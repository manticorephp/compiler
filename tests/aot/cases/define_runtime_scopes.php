<?php
function f() { define("DEF_X", 1); }
var_dump(defined("DEF_X"));
f();
var_dump(DEF_X);
var_dump(constant("DEF_X"));
if ($argc > 0) { define("DEF_Y", [7]); }
var_dump(DEF_Y);
$v = (int)($argc ?? 1) + 41;
var_dump(define("DEF_Z", $v));
echo DEF_Z, "\n";
var_dump(defined("DEF_Z"));

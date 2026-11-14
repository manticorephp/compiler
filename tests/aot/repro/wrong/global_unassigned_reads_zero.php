<?php
// A global read through `global $k` before anything assigns it reads 0 instead of NULL.
// issue: #630
function f() { global $k; return "[" . $k . "]" . var_export($k, true); }
echo f(), "\n";
$k = 5;
echo f(), "\n";

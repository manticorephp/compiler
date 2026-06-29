<?php
$a = [1, "x", 2.5, true];
foreach ($a as $v) {
    if (is_int($v)) { echo "int:$v\n"; }
    elseif (is_string($v)) { echo "str:$v\n"; }
    elseif (is_float($v)) { echo "float:$v\n"; }
    elseif (is_bool($v)) { echo "bool:" . ($v ? "1" : "0") . "\n"; }
}
var_dump($a);

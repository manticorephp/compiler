<?php
// By-reference destructuring `[&$a, &$b] = $arr` copies instead of binding
// issue: #30
$arr = [1, 2];
[&$a, &$b] = $arr;
$a = 10;
$b = 20;
echo json_encode($arr), "\n";

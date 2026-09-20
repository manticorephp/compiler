<?php
function retype(mixed &$v): void {
    if (is_int($v)) { $v = "was int $v"; }
    elseif (is_string($v)) { $v = strlen($v); }
    else { $v = [$v]; }
}
$a = 5; retype($a); var_dump($a);
$b = "hello"; retype($b); var_dump($b);
$c = 1.5; retype($c); var_dump($c);
$d = 70000; retype($d); var_dump($d);
$arr = [1, "two", 3.0];
foreach ($arr as $i => $_) { retype($arr[$i]); }
var_dump($arr);

<?php
// builtins called by runtime name resolve through the sorted table
function userfn($a) { return $a * 2; }
$f = 'strlen'; var_dump($f(' abc '));
$f = 'abs'; var_dump($f(-5));
$f = 'strtoupper'; var_dump($f(' abc '));
$f = 'userfn'; var_dump($f(65));
$f = 'count'; var_dump($f([1, 2, 3]));
$f = 'is_int'; var_dump($f(65));
$f = 'ord'; var_dump($f('a'));
$f = 'chr'; var_dump($f(65));
$f = 'intdiv'; var_dump($f(17, 5));
$f = 'str_repeat'; var_dump($f('ab', 3));
$f = 'max'; var_dump($f(3, 9));
$f = 'trim'; var_dump($f('xxhixx', 'x'));
function loop() { $c = 0; $f = "strlen";
for ($i = 0; $i < 1000; $i++) { $c += $f('abc'); }
return $c; }
echo loop(), "\n";
echo call_user_func('abs', -3), call_user_func('strtoupper', 'x'), "\n";
echo implode(',', array_map('strtoupper', ['a', 'b'])), "\n";

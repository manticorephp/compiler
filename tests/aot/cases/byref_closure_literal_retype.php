<?php
// A closure / arrow-fn literal with a typed scalar by-ref param that stores
// another kind: the caller's slot must read back the stored kind.
$f = function (int &$z): void { $z = "cl"; };
$v = 1; $f($v); var_dump($v);
$g = fn(int &$x) => $x = "arrow";
$w = 1; $g($w); var_dump($w);
$a = function (int &$z): void { $z = [1, 2]; };
$v2 = 1; $a($v2); var_dump($v2);
$n = function (int &$z): void { $z = null; };
$v3 = 1; $n($v3); var_dump($v3);
$fl = fn(int &$x) => $x = 1.5;
$v4 = 1; $fl($v4); var_dump($v4);
$i = function (int &$z): void { $z = $z + 1; };
$v5 = 1; $i($v5); var_dump($v5);
$v6 = 1; call_user_func_array($f, [&$v6]); var_dump($v6);

<?php

// str_ireplace() mirrors str_replace(): an array|string search, and a by-ref
// fourth parameter carrying the replacement count. Matching is
// case-insensitive; the casing of the text BETWEEN matches is preserved.

$out = str_ireplace("L", "_", "Hello WorLd", $count);
var_dump($out, $count);

$arr = str_ireplace(["A", "b"], "x", "ab", $c2);
var_dump($arr, $c2);

$pos = str_ireplace(["a", "b"], ["1", "2"], "AaBb", $c3);
var_dump($pos, $c3);

$miss = str_ireplace("zz", "x", "abc", $c4);
var_dump($miss, $c4);
$empty = str_ireplace("", "x", "abc", $c5);
var_dump($empty, $c5);

$c6 = 99;
$again = str_ireplace("O", "0", "foo BOO", $c6);
var_dump($again, $c6);

var_dump(str_ireplace("O", "0", "foo"));

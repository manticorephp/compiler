<?php

// strpos was strlen+strstr, so a NUL anywhere broke it: a haystack was
// truncated at its first NUL, and a needle CONTAINING one read as empty.
// serialize's mangled property keys ("\0*\0prop") are exactly that.

$nul = chr(0);

var_dump(strpos('hello world', 'o'));
var_dump(strpos('hello world', 'o', 5));
var_dump(strpos('hello world', 'world'));
var_dump(strpos('hello', 'z'));
var_dump(strpos('hello', ''));
var_dump(strpos('hello', '', 3));
var_dump(strpos('hello', 'l', -2));
var_dump(strpos('hello', 'h', 5));
var_dump(strpos('hello', 'hello'));
var_dump(strpos('hello', 'helloo'));
var_dump(strpos('aaa', 'aa'));
var_dump(strpos('aaa', 'aa', 1));

// A NUL needle.
var_dump(strpos($nul . '*' . $nul . 'b', $nul));
var_dump(strpos($nul . '*' . $nul . 'b', $nul, 1));
var_dump(strpos('a' . $nul . 'b', $nul));

// A NUL in the haystack must not end the search.
$h = 'a' . $nul . 'bcd';
var_dump(strpos($h, 'bcd'));
var_dump(strpos($h, 'd'));
var_dump(strlen($h));

// A multi-byte needle that itself contains a NUL.
$k = $nul . 'Cls' . $nul . 'prop';
var_dump(strpos($k, $nul . 'prop'));
var_dump(strpos($k, 'Cls'));

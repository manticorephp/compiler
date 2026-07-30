<?php
// strpos measured the NEEDLE as a C string, so a needle of "\0" was the EMPTY
// one and `strstr` matched at EVERY offset: `substr_count($s, "\0")` — which
// symfony's Table uses to correct multi-byte pad widths — answered strlen()+1,
// and every cell rendered unpadded. Both lengths now come from the string
// header, so a NUL is an ordinary byte on either side.

var_dump(substr_count('name', "\0"));
var_dump(substr_count("a\0b\0c", "\0"));
var_dump(strpos("a\0b", "\0"));
var_dump(strpos("a\0b", "b"));
var_dump(strpos("ab\0cd", "\0cd"));
var_dump(strpos('hello world', 'o'));
var_dump(strpos('hello world', 'o', 5));
var_dump(strpos('hello', 'z'));
var_dump(strpos('hello', 'l', -2));
var_dump(strpos('hello', ''));
var_dump(strpos('hello', '', 3));
var_dump(strpos('aaa', 'aa'));
var_dump(substr_count('aaaa', 'aa'));
var_dump(str_contains("bin\0ary", "\0ar"));
var_dump(str_replace("\0", '-', "a\0b"));

// The padding shape the Table depends on.
$cell = 'name';
$width = 8 + strlen($cell) - strlen($cell) - substr_count($cell, "\0");
var_dump(str_pad(' ' . $cell . ' ', $width));

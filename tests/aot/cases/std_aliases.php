<?php
echo chop("abc \n\t"), "|", chop("abcxx", "x"), "\n";
var_dump(doubleval("1.5x"), doubleval("abc"), doubleval(3));
var_dump(key_exists('a', ['a' => 1]), key_exists(2, [1, 2]), key_exists('z', []));
echo bin2hex(utf8_encode("\xe9\x80ab")), "\n";
var_dump(strcoll('a', 'b') < 0, strcoll('b', 'a') > 0, strcoll('a', 'a'), strcoll('a', 'abc') < 0, strcoll('abc', 'a') > 0);
var_dump(ini_alter('precision', '14') !== null);
echo get_include_path() === '' ? 'empty' : 'set', "\n";
var_dump(set_include_path(''));
$old = set_include_path('/x:/y');
var_dump(is_string($old), get_include_path(), ini_get('include_path'));
set_include_path($old);
var_dump(get_include_path() === $old);

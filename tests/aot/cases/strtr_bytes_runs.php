<?php
// strtr() byte form: last duplicate wins, the shorter map truncates, untouched runs survive, NUL bytes map.
$U = '_ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $L = '-abcdefghijklmnopqrstuvwxyz';
foreach (['content-type', 'Content-Type', 'X_FORWARDED_FOR', 'ABC', 'abc', '', 'a', 'Z', 'aZb_c', "x\0Y"] as $s) {
    echo json_encode(strtr($s, $U, $L)), "\n";
}
echo strtr('abcabc', 'aab', 'xyz'), "\n";
echo strtr('abcabc', 'abc', 'xy'), "\n";
echo strtr('abc', '', 'x'), strtr('abc', 'a', ''), "|\n";
echo json_encode(strtr("a\0b\0", "\0", '.')), "\n";
echo strtr('hello world', 'lo', '01'), "\n";
echo strtr('aaaa', 'a', 'b'), strtr('zzz', 'a', 'b'), "\n";

<?php
// (int) of a numeric string with a FLOAT-literal prefix parses the prefix as a
// double and truncates (php >= 7.1): (int)"1e3" is 1000, not 1.
function f(mixed $m): int { return (int)$m; }
var_dump(f("123"), f("12abc"), f(1.9), f(true), f(null), f("1e3"), f("1.9"), f(" 42"), f("1e"), f("abc"));
$s = "1e3"; var_dump((int)$s, intval($s), intval("2.5"), intval("0x1A", 16), intval("012", 0), intval("-1e2"));
var_dump((int)"9223372036854775808", (int)"-7.5e1");

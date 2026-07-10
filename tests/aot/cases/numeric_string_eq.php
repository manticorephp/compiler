<?php
// Loose == juggles a numeric string against a number; strict === does not.
var_dump("10" == 10);      // true
var_dump(10 == "10");      // true
var_dump("1e2" == 100);    // true
var_dump("3.14" == 3.14);  // true
var_dump(" 10" == 10);     // true (leading whitespace numeric)
var_dump("abc" == 0);      // false (PHP 8: non-numeric)
var_dump("10abc" == 10);   // false
var_dump("0" == 0);        // true
var_dump("10" != 5);       // true
var_dump("10" === 10);     // false (strict)
var_dump("10" !== 10);     // true
function cmp(?string $x, int $y): bool { return $x == $y; }
var_dump(cmp("42", 42));   // true
var_dump(cmp(null, 0));    // true
var_dump(cmp(null, 7));    // false

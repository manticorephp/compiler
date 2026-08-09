<?php

// sscanf's by-reference form. php spells it `sscanf($s, $f, &...$vars)`, and a
// by-ref VARIADIC pack does not exist in this compiler — the caller packs
// trailing arguments into one array literal, so the pack is a VALUE and the
// callee's writes land in a throwaway alloca. The trailing lvalues are
// desugared at the CALL SITE instead, the same treatment array_multisort gets.
//
// The corpus witness is symfony/console Cursor::getCurrentPosition, which writes
// `sscanf($code, "\033[%d;%dR", $row, $col)` — it happens to be the shape where
// both conversions succeed, which is why the three bugs below survived: the
// RETURN was the specifier count rather than the number of values assigned, a
// subject that matched nothing answered 0 instead of -1, and its variables came
// back as denormal 0.0 rather than NULL.

// the Cursor shape: everything converts
$n = sscanf("\033[12;34R", "\033[%d;%dR", $row, $col);
var_dump($n, $row, $col);

// a conversion fails part-way: php assigns what it got and stops
$n1 = sscanf("5", "%d %d", $y, $z);
var_dump($n1, $y, $z);

// nothing matches at all, but the subject is non-empty
$n2 = sscanf("nope", "age: %d", $x);
var_dump($n2, $x);

// an empty subject — php's -1, and the variable is left NULL
$n3 = sscanf("", "%d", $w);
var_dump($n3, $w);

// a literal mismatch mid-format
$n4 = sscanf("a=1;X=2", "a=%d;b=%d", $p, $q);
var_dump($n4, $p, $q);

// more specifiers than the subject can fill
$n5 = sscanf("x", "%d %d %d", $d1, $d2, $d3);
var_dump($n5, $d1, $d2, $d3);

// mixed conversions, all succeeding
$n6 = sscanf("age: 25 name: Bob", "age: %d name: %s", $age, $name);
var_dump($n6, $age, $name);

// the ARRAY form must be untouched by any of this: php returns exactly one
// entry per non-suppressed specifier whenever it returns an array at all
var_dump(sscanf("age: 25 name: Bob", "age: %d name: %s"));
var_dump(sscanf("5", "%d %d"));
var_dump(sscanf("nope", "age: %d"));
var_dump(sscanf("", "%d"));
var_dump(sscanf("  ", "%d"));
var_dump(sscanf("  x", "%d"));
var_dump(sscanf("x", "%d %d %d"));
var_dump(sscanf("a=1;X=2", "a=%d;b=%d"));
var_dump(sscanf("1 2 3", "%d"));

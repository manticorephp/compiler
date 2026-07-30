<?php

// `and` / `or` / `xor` bind LOOSER than `=`, which is the entire reason they
// exist alongside && / || / ^.
$ok = 1 or 0;
var_dump($ok);          // int(1) — the assignment wins, not the `or`
$ok2 = 0 or 1;
var_dump($ok2);         // int(0)
$v = true && false;
var_dump($v);           // bool(false) — && DOES bind tighter than =

$c = true;
$d = false;
$c and print "and-fired\n";
$d and print "never\n";
$d or print "or-fired\n";
$c or print "never either\n";

// Precedence among themselves, loosest first: or, xor, and.
var_dump(true xor false);
var_dump(true xor true);
var_dump(false xor false);
var_dump(true and false);
var_dump(false or true);

// xor over truthy non-bools, since it is defined on truthiness.
var_dump(1 xor 0);
var_dump("a" xor "");
var_dump([] xor [1]);

// Chained, left-associative.
var_dump(true xor true xor true);
var_dump(true and true and false);

// `print` binds TIGHTER than the word operators.
print "p\n" and print "q\n";

// Mixed with the symbol operators, which bind tighter.
$x = true;
$y = false;
var_dump($x || $y and $y);
var_dump($x && $y or $x);

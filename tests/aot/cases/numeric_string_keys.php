<?php

// php normalises a canonical numeric-string LITERAL array key to an int key:
// $a["42"] IS $a[42], and ["0"=>x] builds an int-keyed array. Non-canonical
// forms ("01", "1.0", "-0", "x") stay string keys.

$a = [];
$a["0"] = "z0";
$a["1"] = "z1";
$a["42"] = "z42";
$a["-7"] = "zn7";
$a["01"] = "keep01";     // leading zero -> string
$a["1.0"] = "keepf";     // not an int -> string
$a["-0"] = "keepneg0";   // -0 -> string
$a["x"] = "zx";
var_dump($a);

// array literal with canonical numeric string keys -> int keys
$b = ["0" => "b0", "3" => "b3", "-2" => "bn2", "07" => "b07", "k" => "bk"];
var_dump($b);

// int literal and canonical string literal address the SAME slot
$c = [];
$c[5] = "int5";
$c["5"] = "overwrites";
var_dump($c);

// read back through both int and string literal
$d = ["10" => "ten"];
var_dump($d[10]);
var_dump($d["10"]);
var_dump(isset($d[10]), isset($d["10"]));

// contiguous canonical keys behave as a list
$e = ["0" => "a", "1" => "b", "2" => "c"];
var_dump(array_is_list($e));
$e[] = "d";
var_dump($e);

// An out-of-int64 digit run fails the fold's `(string)(int)$s === $s`
// round-trip (the cast saturates) and stays a STRING key, exactly like php.
$f = ["99999999999999999999" => "huge", "12345678901234567890" => "big"];
var_dump($f);

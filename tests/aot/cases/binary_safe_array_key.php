<?php
// Binary-safe assoc keys: a NUL in a string key must not collide/truncate.
// Hash is FNV over len@-16 bytes; key compare is memcmp+len (__mir_str_eq).
$a = [];
$a["x" . chr(0) . "y"] = 1;
$a["x" . chr(0) . "z"] = 2;   // differs only after the NUL — must be distinct
$a["x"] = 3;                   // prefix of the others — must be distinct
echo count($a), "\n";                 // 3
echo $a["x" . chr(0) . "y"], "\n";    // 1
echo $a["x" . chr(0) . "z"], "\n";    // 2
echo $a["x"], "\n";                   // 3
echo isset($a["x" . chr(0) . "q"]) ? "y" : "n", "\n"; // n

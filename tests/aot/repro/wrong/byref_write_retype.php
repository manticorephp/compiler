<?php
// A by-ref write that changes the caller variable's type answers an address
// issue: #31
function w(mixed &$v): void { $v = "s"; }
$x = 1;
w($x);
var_dump($x);
function f(&$x) { $x = 5; }
foreach ([new stdClass] as $d) {}
f($d);
var_dump($d);

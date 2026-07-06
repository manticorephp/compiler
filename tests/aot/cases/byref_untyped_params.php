<?php
// Untyped by-ref params (&$p with no hint) mutate string/array/int args — the
// type is inferred from call sites. Regression guard for the cell-misread bug.
function append(&$s) { $s = $s . "!"; }
function upper(&$s) { $s = strtoupper($s); }
function push(&$a) { $a[] = 9; }
function bump(&$x) { $x++; }
$str = "hi"; append($str); upper($str); echo $str, "\n";
$arr = [1, 2]; push($arr); echo implode(",", $arr), "\n";
$n = 5; bump($n); echo $n, "\n";

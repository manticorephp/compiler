<?php
// A stdlib fn with a bare-`array` param (erased to "" in the .sig) must receive
// a concrete assoc RAW, not boxed to a cell — else the raw-walking callee derefs
// the array tag (SIGSEGV). Was: array_key_exists / array_slice crashed on assoc.
$a = ["x" => 5, "y" => 1, "z" => 3];
echo array_key_exists("x", $a) ? "has-x\n" : "no-x\n";   // has-x
echo array_key_exists("q", $a) ? "has-q\n" : "no-q\n";   // no-q
echo in_array(3, $a) ? "in\n" : "out\n";                 // in
echo array_search(1, $a), "\n";                          // y
echo count(array_slice([100, 200, 300], 1)), "\n";       // 2
echo implode(",", array_keys($a)), "\n";                 // x,y,z

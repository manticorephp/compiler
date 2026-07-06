<?php
// String-keyed array element by reference: alias, mutate-through, pass by-ref,
// and auto-vivification of an absent string key.
function bump(int &$v) { $v += 100; }

$m = ["x" => 10, "y" => 20];

// bind an alias to a string-keyed element and mutate through it
$r = &$m["x"];
$r = 5;
$r++;
echo $m["x"], " ", $m["y"], "\n";      // 6 20

// pass a string-keyed element by reference
bump($m["y"]);
echo $m["y"], "\n";                     // 120

// auto-vivify an absent string key (PHP by-ref creates it)
$n = ["a" => 1];
$s = &$n["fresh"];
$s = 42;
echo $n["fresh"], " ", count($n), "\n"; // 42 2

// two aliases into the same assoc
$cfg = ["w" => 1, "h" => 2];
$w = &$cfg["w"];
$h = &$cfg["h"];
$w = 800;
$h = 600;
echo $cfg["w"], "x", $cfg["h"], "\n";   // 800x600

// string variable (not literal) as the key
$k = "score";
$scores = ["score" => 0];
$ref = &$scores[$k];
$ref = 99;
echo $scores["score"], "\n";            // 99

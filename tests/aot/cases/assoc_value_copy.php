<?php
// PHP array value semantics for string-keyed (assoc) arrays: `$b = $a` shares
// the buffer read-only but copies-on-write, so mutating one never affects the
// other.

// snapshot: mutate one, the other keeps the old value
$a = ["k" => 1, "j" => 2];
$b = $a;
$a["k"] = 99;
echo $a["k"], " ", $b["k"], "\n";        // 99 1

// a function receives an assoc "by value" — mutating the param leaves the
// caller's array untouched
function tweak($m) { $m["x"] = 100; return $m["x"]; }
$src = ["x" => 5];
echo tweak($src), " ", $src["x"], "\n";   // 100 5

// an append into one alias does not grow the other
$p = ["a" => 1];
$q = $p;
$p["b"] = 2;
echo count($p), " ", count($q), "\n";     // 2 1

// pure read-only aliases share safely
$r = ["m" => 7];
$s = $r;
echo $r["m"], " ", $s["m"], "\n";         // 7 7

// string-valued snapshot
$c = ["name" => "old"];
$d = $c;
$c["name"] = "new";
echo $c["name"], " ", $d["name"], "\n";   // new old

// by-ref element write copies-on-write too
function bump(int &$v) { $v += 100; }
$e = ["n" => 1];
$f = $e;
bump($e["n"]);
echo $e["n"], " ", $f["n"], "\n";         // 101 1

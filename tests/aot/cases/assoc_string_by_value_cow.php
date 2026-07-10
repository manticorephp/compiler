<?php
// A by-value array param whose element type erased to unknown/cell (a bare
// `array` hint with string / heterogeneous values) is still a VALUE: a keyed
// store must stay private. copy-on-entry fires for any DECLARED-array param
// mutated in place (a flat copy — sound for scalar/string/obj/cell elements).
function sset(array $x): string { $x["k"] = "z"; return $x["k"]; }
$s = ["k" => "a"];
$rs = sset($s);
echo $s["k"], " ", $rs, "\n";       // a z

function hset(array $x): int { $x["n"] = 5; return count($x); }
$h = ["n" => "x", "m" => 1];        // heterogeneous values → cell
$rh = hset($h);
echo $h["n"], " ", $rh, "\n";       // x 2

function push(array $x): int { $x[] = new stdClass(); return count($x); }
$o = [new stdClass(), new stdClass()];
$rp = push($o);
echo count($o), " ", $rp, "\n";     // 2 3

// Heterogeneous outer (array + string → vec[cell]) with a nested-array element:
// a tag-aware copy separates the boxed inner array so `$x[0][] = …` stays private.
function nestcell(array $x): void { $x[0][] = 9; }
$d = [[1, 2], "tag"];
nestcell($d);
echo count($d[0]), "\n";            // 2

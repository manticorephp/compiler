<?php
// by-ref params across the patterns that used to need workarounds:
// scalar, string, array append, assoc, nested, recursive, method.
function inc(int &$x): void { $x = $x + 1; }
function push(array &$a): void { $a[] = 9; }
function setk(array &$a): void { $a["k"] = 7; }
function emit(string &$o, string $s): void { $o = $o . $s; }
function build(array &$acc, int $n): void { if ($n <= 0) return; $acc[] = $n; build($acc, $n - 1); }
class Box { public function bump(int &$x): void { $x = $x + 5; } }

$a = 1; inc($a); echo "inc=$a\n";
$c = [1]; push($c); echo "push=", count($c), "\n";
$as = []; setk($as); echo "assoc=", $as["k"], "\n";
$o = ""; emit($o, "x"); emit($o, "y"); echo "str=$o\n";
$acc = []; build($acc, 3); echo "recur=", count($acc), " ", $acc[0], $acc[1], $acc[2], "\n";
$bx = new Box(); $m = 1; $bx->bump($m); echo "method=$m\n";

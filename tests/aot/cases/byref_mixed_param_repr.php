<?php

/*
 * A CONCRETE lvalue handed to a `mixed &$var` parameter. The caller's slot
 * holds a raw word and the callee reads a tagged cell, so an `int 3` used to
 * read back as float(1.5E-323). A TYPED by-ref param (`int &$n`) was always
 * right — that is the discriminator.
 *
 * An ARRAY crosses the same channel: a CONCRETE-element one is rebuilt with
 * boxed elements on the way in and de-cellified on the way back, so the callee
 * reads real values and the caller keeps its own raw-element representation. A
 * cell/unknown-element array is already self-describing and crosses flat.
 */

function take(mixed $v): string { return gettype($v) . ':' . json_encode($v); }

function fwd(mixed &$r): string { return take($r); }
function fwdUntyped(&$r): string { return take($r); }
function bump(mixed &$r): void { $r = 100; }
function append(mixed &$r): void { $r = $r . '!'; }

class K
{
    public function fwd(mixed &$r): string { return take($r); }
    public static function sfwd(mixed &$r): string { return take($r); }
    public function viaLocal(mixed &$r): string { $c = $r; return take($c); }
}

$i = 3;    echo fwd($i), "\n";
$s = 'q';  echo fwd($s), "\n";
$f = 1.5;  echo fwd($f), "\n";
$b = true; echo fwd($b), "\n";
$n = null; echo fwd($n), "\n";
$o = new K(); echo gettype(fwd($o)), "\n";

$u = 5; echo fwdUntyped($u), "\n";

$k = new K();
$m = 7;  echo $k->fwd($m), "\n";
$m2 = 8; echo $k->viaLocal($m2), "\n";
$m3 = 9; echo K::sfwd($m3), "\n";

// the write-back: what the callee leaves must land in the caller's slot
$w = 1;
bump($w);
echo take($w), "\n";
$t = 'a';
append($t);
echo take($t), "\n";

// arrays across the same channel, by element repr
$ints = [1, 2, 3];            echo fwd($ints), "\n";
$strs = ['a', 'b'];           echo fwd($strs), "\n";
$flts = [1.5, 2.5];           echo fwd($flts), "\n";
$mix  = [1, 'a', 2.5];        echo fwd($mix), "\n";
$as   = ['k' => 1, 'j' => 2]; echo fwd($as), "\n";
$nest = [[1, 2], [3]];        echo fwd($nest), "\n";
$empty = [];                  echo fwd($empty), "\n";
$ma = [4, 5];                 echo $k->fwd($ma), "\n";
$sa = [6, 7];                 echo K::sfwd($sa), "\n";

// the callee's mutation must come back, and the caller must still read its
// OWN elements raw afterwards
function push(mixed &$r): void { $r[] = 99; }
function setk(mixed &$r): void { $r['n'] = 'added'; }
function replace(mixed &$r): void { $r = [7, 8]; }

$p = [1, 2];   push($p);    echo take($p), ' ', var_export($p[0], true), "\n";
$q = ['a' => 1]; setk($q);  echo take($q), "\n";
$z = [1, 2];   replace($z); echo take($z), ' ', var_export($z[0], true), "\n";
$sum = 0; foreach ($p as $v) { $sum += $v; }
echo $sum, ' ', count($p), ' ', $p[count($p) - 1], "\n";
$nsum = 0; foreach ($nest as $row) { foreach ($row as $v) { $nsum += $v; } }
echo $nsum, "\n";

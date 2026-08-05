<?php

/*
 * A CONCRETE lvalue handed to a `mixed &$var` parameter. The caller's slot
 * holds a raw word and the callee reads a tagged cell, so an `int 3` used to
 * read back as float(1.5E-323). A TYPED by-ref param (`int &$n`) was always
 * right — that is the discriminator.
 *
 * Arrays are deliberately NOT covered: they keep the plain address path.
 */

function take(mixed $v): string { return gettype($v) . ':' . var_export($v, true); }

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

<?php
interface I {} class D implements I { public $v; } class E extends D {} class F {}
function t(mixed $x, mixed $t) { return $x instanceof $t ? "Y" : "n"; }
function u(mixed $x, D $t) { return $x instanceof $t ? "Y" : "n"; }
function w(mixed $x, string $t) { return $x instanceof $t ? "Y" : "n"; }
$d = new D; $e = new E; $f = new F;
echo t($d, $d), t($e, $d), t($d, $e), t($f, $d), t(null, $d), t(5, $d), t($e, 'D'), t($f, 'D'), t($e, 'I'), "\n";
echo u($d, $d), u($e, $d), u($d, $e), u($f, $d), u(null, $d), u(5, $d), "\n";
echo w($e, 'D'), w($f, 'D'), w($e, 'I'), w($d, 'E'), "\n";
$clone = clone $d;
echo ($e instanceof $clone) ? "Y" : "n", ($f instanceof $clone) ? "Y" : "n", "\n";

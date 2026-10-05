<?php
// A typed scalar by-ref param that stores another kind, handed an array
// ELEMENT (local, property, static property): the caller's buffer must hold
// the stored kind.
function h(int &$z): void { $z = "x$z"; }
$list = [1, 2];
foreach ($list as $k => $_) { h($list[$k]); }
var_dump($list);
$l2 = [5, 6]; h($l2[1]); var_dump($l2);
final class M { public function m(int &$z): void { $z = "m$z"; } public static function s(int &$z): void { $z = "s$z"; } }
$l3 = [7, 8]; (new M)->m($l3[0]); M::s($l3[1]); var_dump($l3);
$c = function (int &$z): void { $z = "c$z"; };
$l4 = [1, 2]; $c($l4[0]); var_dump($l4);
$a = fn(int &$z) => $z = "a$z";
$l5 = [1, 2]; $a($l5[1]); var_dump($l5);
final class P { public array $arr = [1, 2]; public static array $sarr = [3, 4]; }
$o = new P; h($o->arr[0]); var_dump($o->arr);
h(P::$sarr[1]); var_dump(P::$sarr);
$m = ['k' => 1]; h($m['k']); var_dump($m);
$w = [1, 2, 3]; array_walk($w, function (int &$v) { $v = "s"; }); var_dump($w);
$w2 = [1, 2, 3]; array_walk($w2, function (&$v, $k) { $v = $v * 10; }); var_dump($w2);

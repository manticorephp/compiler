<?php
interface I { function r(string &$s); function n(int $a, int $b = 5, int $c = 7); }
abstract class A { abstract function q(int $n, int $v = 3); }
class C implements I {
    function r(string &$s) { $s .= "!"; }
    function n(int $a, int $b = 5, int $c = 7) { echo "$a-$b-$c\n"; }
}
class D extends A { function q(int $n, int $v = 3) { return $n . '/' . $v; } }
function viaI(I $i) {
    $s = "x"; $f = $i->r(...); $f($s); $f($s); echo $s, "\n";
    $g = $i->n(...); $g(c: 9, a: 1); $g(1); $g(1, 2); $g(b: 4, a: 3);
}
function viaA(A $a) { $f = $a->q(...); echo $f(1, 2), ' ', $f(v: 8, n: 9), ' ', $f(5), "\n"; }
function viaM(mixed $m) { $s = "y"; $f = $m->r(...); $f($s); echo $s, "\n"; }
viaI(new C); viaA(new D); viaM(new C);
$cl = function (int $a, int $b = 5, int $c = 7) { echo "$a-$b-$c\n"; };
$cl(c: 9, a: 1);

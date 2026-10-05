<?php
// An UNTYPED by-ref param reached through a closure invoke, a first-class
// callable or a method call shares the caller's slot: the callee may change
// its kind, and the caller must read back what the callee wrote.
function inc(&$x) { $x++; }
function tostr(&$x) { $x = "s" . $x; }
interface I { function m(&$x); }
abstract class A { abstract function m(&$x); }
class O extends A implements I {
    function m(&$x) { $x++; }
    function r(&$x) { $x = [$x]; }
    static function s(&$x) { $x .= "!"; }
}
function fnFcc() { $n = 1; $f = inc(...); $f($n); var_dump($n); $n = 2; $f = tostr(...); $f($n); var_dump($n); }
function methodFcc() { $o = new O; $n = 1; $f = $o->m(...); $f($n); var_dump($n); $f = $o->r(...); $f($n); var_dump($n); }
function staticFcc() { $n = 1; $f = O::s(...); $f($n); var_dump($n); }
function closureLit() { $n = 1; $c = function (&$x) { $x++; }; $c($n); var_dump($n);
    $c = function (&$x) { $x = "s"; }; $c($n); var_dump($n); }
function arrowFn() { $n = 1; $c = fn(&$x) => $x++; $c($n); var_dump($n); }
function capturing() { $k = 10; $n = 1; $c = function (&$x) use ($k) { $x = $x + $k . "!"; }; $c($n); var_dump($n); }
function directMethod() { $o = new O; $n = 1; $o->r($n); var_dump($n); $m = 1; O::s($m); var_dump($m); }
function ifaceFcc(I $i) { $n = 1; $f = $i->m(...); $f($n); var_dump($n); }
function abstractFcc(A $a) { $n = 1; $f = $a->m(...); $f($n); var_dump($n); }
function cufa() { $n = 1; call_user_func_array('inc', [&$n]); var_dump($n); $c = function (&$x) { $x = "q"; };
    call_user_func_array($c, [&$n]); var_dump($n); }
fnFcc(); methodFcc(); staticFcc(); closureLit(); arrowFn(); capturing(); directMethod();
ifaceFcc(new O); abstractFcc(new O); cufa();

class V { function g($x) { $x++; echo "V $x\n"; } }
class R { function g(&$x) { $x++; echo "R $x\n"; } }
function runM(mixed $m) { $n = 1; $f = $m->g(...); $f($n); echo $n, "\n"; }
runM(new V);
runM(new R);

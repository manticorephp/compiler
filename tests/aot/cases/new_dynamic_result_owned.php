<?php
class Res { public function __construct(public string $n) {} public function __destruct() { echo "res-drop {$this->n}\n"; } }
class Mw { public function __construct(public string $n = "mw") {} public function handle($req, $next) { return $next($req); } public function __destruct() { echo "mw-drop {$this->n}\n"; } }
function d($c) { return new $c(); }
function e($c) { $o = new $c(); return $o; }
function t1() { d('Mw'); echo "t1\n"; }
function t2() { $x = d('Mw'); echo "t2\n"; }
function t3() { $x = e('Mw'); echo "t3\n"; }
function t4() { $x = new Mw("t4"); $x = "s"; echo "t4 reassigned\n"; }
function t5($p) { $p = new Mw("t5"); echo "t5 param retyped\n"; }
function t6($p) { $p = d('Mw'); echo "t6 param retyped dyn\n"; }
function t7() { $f = function ($p) { $p = new Mw("t7"); echo "t7 closure param\n"; }; $f('s'); echo "t7 end\n"; }
function t8() { $pipe = 's'; $f = function () use ($pipe) { $pipe = new Mw("t8"); echo "t8 capture retyped\n"; }; $f(); echo "t8 end\n"; }
function t9() { $pipe = 's'; $f = function () use ($pipe) { $pipe = new Mw("t9"); echo "t9 call\n"; return $pipe->handle(1, fn ($x) => $x); }; $f(); echo "t9 end\n"; }
function t10() { $m = new Mw("t10"); $x = $m->{'handle'}(...[new Res("t10"), fn ($x) => $x]); echo "t10 body\n"; }
function t11() { $m = new Mw("t11"); $n = 'handle'; $x = method_exists($m, $n) ? $m->{$n}(new Res("t11"), fn ($x) => $x) : null; echo "t11 body\n"; }
function id($c) { return $c; }
function t12() { $x = id(new Res("t12")); echo "t12 body\n"; }
foreach (['t1','t2','t3','t4','t7','t8','t9','t10','t11','t12'] as $t) { $t(); echo "== $t\n"; }
t5('s'); echo "== t5\n"; t6('s'); echo "== t6\n";
function t13() { $c = 'Mw'; $x = new $c("t13"); echo "t13 local\n"; $x = null; echo "t13 cleared\n"; }
function t14() { $c = 'Mw'; (new $c("t14"))->handle(1, fn ($x) => $x); echo "t14 receiver\n"; }
class Box { public $v; public array $l = []; public static $s; }
function t16() { $c = 'Mw'; $b = new Box(); $b->v = new $c("t16"); echo "t16 stored\n"; }
function t17() { $c = 'Mw'; $b = new Box(); $b->l[] = new $c("t17"); echo "t17 appended\n"; }
function t18() { $c = 'Mw'; $a = [new $c("t18")]; echo "t18 literal\n"; }
function t19() { $c = 'Mw'; $f = fn () => new $c("t19"); $o = $f(); echo "t19 arrow\n"; }
function t20($c, array $args) { $o = new $c(...$args); echo "t20 spread\n"; return $o; }
function t21() { $c = 'Mw'; $o = true ? new $c("t21") : null; echo "t21 ternary\n"; }
function t22() { $c = 'Mw'; $o = id(new $c("t22")); echo "t22 arg\n"; }
function t24() { $g = (function () { $c = 'Mw'; yield new $c("t24"); })(); foreach ($g as $v) { echo "t24 {$v->n}\n"; } }
function t25() { foreach (['Mw', 'Res'] as $c) { $o = new $c("t25"); echo "t25 $c\n"; } }
function t26() { $c = 'ArrayObject'; $o = new $c([1, 2]); echo count($o), "\n"; }
function t27() { Box::$s = 'Mw'; $n = Box::$s; Box::$s = new $n("t27"); echo "t27 static\n"; Box::$s = null; echo "t27 cleared\n"; }
foreach (['t13','t14','t16','t17','t18','t19','t21','t22','t24','t25','t26','t27'] as $t) { $t(); echo "== $t\n"; }
$z = t20('Mw', ["t20"]); echo "got\n"; unset($z); echo "== t20\n";

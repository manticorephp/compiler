<?php
class H { public function __construct(public string $n) {} public function __destruct() { echo "drop {$this->n}\n"; } }
class C { public function __construct(public H $h) {} public function m() { return new C(new H("res-" . $this->h->n)); } public function s() { return 1; } }
class D2 extends C { public function m() { return 5; } }
class MG { public function __construct(public ?H $h = null) {} public function __call($n, $a) { return "magic $n"; } }
function mg(string $n): mixed { return new MG(new H($n)); }
function t7() { $x = mg("t7")->onlyMagic(); echo "$x\n"; }
function t8() { $x = mg("t8")->m(); echo "$x\n"; }
function mk(string $n): mixed { return new C(new H($n)); }
function t1() { mk("t1")->s(); echo "t1 body\n"; }
function t2() { $x = mk("t2")->m(); echo "t2 body\n"; unset($x); echo "t2 end\n"; }
function t3() { $x = mk("t3")->m()->m(); echo "t3 body\n"; unset($x); echo "t3 end\n"; }
function t4() { $y = mk("t4"); $x = $y->m(); unset($y); echo "t4 body\n"; unset($x); echo "t4 end\n"; }
function t5() { $x = (function () { return new C(new H("t5")); })()->m(); echo "t5 body\n"; unset($x); echo "t5 end\n"; }
function t6() { $x = (function () { return new C(new H("t6")); })()->s(); echo "t6 body\n"; }
foreach (['t1','t2','t3','t4','t5','t6','t7','t8'] as $t) { $t(); echo "== $t\n"; }
new MG();
$c = 'C'; $x = [$c, 'nope'];
class St { public static function one($n) { return new H($n); } }
function u1() { $c = 'St'; $m = 'one'; $v = $c::$m("u1"); echo "u1 body\n"; unset($v); echo "u1 end\n"; }
function u2() { $f = ['St', 'one']; $v = $f("u2"); echo "u2 body\n"; unset($v); echo "u2 end\n"; }
function u3() { $f = [new C(new H("u3r")), 'm']; $v = $f(); echo "u3 body\n"; unset($v); unset($f); echo "u3 end\n"; }
function u4() { $f = [new C(new H("u4r")), 's']; $v = $f(); var_dump($v); }
function u5() { $f = ['St', 'one']; return $f("u5")->n; }
u1(); u2(); u3(); u4(); var_dump(u5());

<?php
#[AllowDynamicProperties]
class P { private $pp = 1; protected $pq = 2; public $pr = 3; public static $st = 9; }
#[AllowDynamicProperties]
class C extends P {
    private $cp = 4;
}
function show(array $a): void { foreach ($a as $k => $v) { echo str_replace("\0", '\0', (string)$k), '=', is_object($v) ? 'obj' : $v, "\n"; } echo "--\n"; }
$c = new C();
show((array) $c);
$c->dyn = 7;
show((array) $c);
echo json_encode($c), "\n";
function erased(mixed $o): array { return (array) $o; }
show(erased($c));
show(erased(new P()));
$o = new P();
$o->nest = new C();
$arr = (array) $o;
show($arr);
class D { public function __construct(public int $a = 1, protected int $b = 2, private int $c = 3) {} }
show((array) new D());

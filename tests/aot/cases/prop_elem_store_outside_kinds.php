<?php
class M { public array $xs = []; }
$m = new M();
$m->xs[] = "a"; $m->xs[] = 42; $m->xs[] = 3.5; $m->xs[] = true;  // MIXED from outside -> must be cell
foreach ($m->xs as $v) { var_dump($v); }

class I { public array $ns = []; }
$i = new I(); $i->ns[] = 1; $i->ns[] = 2;
echo array_sum($i->ns), "\n";

class O { public array $os = []; }
class Item { public function __construct(public string $tag) {} }
$o = new O(); $o->os[] = new Item("x"); $o->os[] = new Item("y");
foreach ($o->os as $it) { echo $it->tag; }
echo "\n";

class Base { public array $vals = []; }
class Der extends Base {}
$d = new Der(); $d->vals[] = "s1"; $d->vals[] = "s2";   // subclass receiver, prop on Base
echo implode("|", $d->vals), "\n";

class K { public array $map = []; }
$k = new K(); $k->map["a"] = 1; $k->map["b"] = 2;       // string-keyed assoc from outside
echo $k->map["a"] + $k->map["b"], "\n";

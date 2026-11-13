<?php
// Cycles through a subclass, an interface, a closure, an untyped and an object slot are still collected; acyclic classes still die.
final class Count { public static int $n = 0; }
abstract class Base { }
interface Linkable { }
final class Leaf { public int $n = 0; public string $s = 'x'; public function __destruct() { Count::$n++; } }
final class LeafHolder { public ?Leaf $leaf = null; public function __destruct() { Count::$n++; } }
final class Holder { public ?Base $x = null; public ?LeafHolder $lh = null; public function __destruct() { Count::$n++; } }
final class Sub extends Base { public ?Holder $h = null; public function __destruct() { Count::$n++; } }
final class ViaIface { public ?Linkable $l = null; public function __destruct() { Count::$n++; } }
final class Impl implements Linkable { public ?ViaIface $v = null; public function __destruct() { Count::$n++; } }
final class ViaClosure { public ?Closure $fn = null; public function __destruct() { Count::$n++; } }
final class ViaUntyped { public $any; public function __destruct() { Count::$n++; } }
final class ViaObject { public ?object $o = null; public function __destruct() { Count::$n++; } }
final class Self_ { public ?Self_ $me = null; public function __destruct() { Count::$n++; } }

function report(string $what): void {
    gc_collect_cycles();
    echo $what, ': ', Count::$n, "\n";
    Count::$n = 0;
}

for ($i = 0; $i < 3; $i++) {
    $h = new Holder; $s = new Sub; $h->x = $s; $s->h = $h;
    $h->lh = new LeafHolder; $h->lh->leaf = new Leaf;
    unset($h, $s);
}
report('subclass');
for ($i = 0; $i < 3; $i++) { $a = new ViaIface; $b = new Impl; $a->l = $b; $b->v = $a; unset($a, $b); }
report('interface');
for ($i = 0; $i < 3; $i++) { $c = new ViaClosure; $c->fn = function () use ($c) { return $c; }; unset($c); }
report('closure');
for ($i = 0; $i < 3; $i++) { $u = new ViaUntyped; $v = new ViaUntyped; $u->any = $v; $v->any = $u; unset($u, $v); }
report('untyped');
for ($i = 0; $i < 3; $i++) { $o = new ViaObject; $o->o = $o; unset($o); }
report('object');
for ($i = 0; $i < 3; $i++) { $m = new Self_; $m->me = $m; unset($m); }
report('self');
$leaf = new Leaf;
for ($i = 0; $i < 3; $i++) { $lh = new LeafHolder; $lh->leaf = $leaf; $lh2 = $lh; unset($lh); unset($lh2); }
echo 'acyclic: ', Count::$n, "\n";
unset($leaf);
echo 'leaf: ', Count::$n, "\n";

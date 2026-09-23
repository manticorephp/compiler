<?php
// A `mixed` / nullable property owns what it holds: the object's death, an
// overwrite and an unset give the value back, and a getter's return is a count
// of its own — so reading the property through one no longer keeps the
// overwritten value alive.
class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~{$this->n}\n"; }
}
class P { public mixed $o = null; public ?string $s = null; }

function dies(): void { $p = new P(); $p->o = new D('in-prop'); unset($p); echo "p gone\n"; }
dies();
function overwrite(): void { $p = new P(); $p->o = new D('ow1'); $p->o = new D('ow2'); echo "ow\n"; }
overwrite();
echo "after ow\n";
function borrowed(): void { $d = new D('bor'); $p = new P(); $p->o = $d; unset($d); echo "d gone\n"; unset($p); echo "p gone\n"; }
borrowed();
function fromLoop(array $xs): void { $p = new P(); foreach ($xs as $x) { $p->o = $x; } echo "loop\n"; }
fromLoop([new D('c1'), new D('c2')]);
echo "after loop\n";
function cloned(): void { $p = new P(); $p->s = str_repeat('a', 5); $p->o = new D('shared'); $q = clone $p; unset($p); echo "p gone ", $q->s, "\n"; unset($q); echo "q gone\n"; }
cloned();

class M { public mixed $m = null; public function get(): mixed { return $this->m; } }
function viaGetter(): void { $x = new M(); $x->m = new D('m1'); $g = $x->get(); $x->m = new D('m2'); echo "got ", $g->n, "\n"; unset($g); echo "g gone\n"; unset($x); echo "x gone\n"; }
viaGetter();
class O { public ?D $d = null; public function get(): D { return $this->d; } }
function objGetter(): void { $x = new O(); $x->d = new D('o1'); $g = $x->get(); $x->d = new D('o2'); echo "got ", $g->n, "\n"; unset($g); echo "g gone\n"; unset($x); echo "x gone\n"; }
objGetter();
function closureRead(): void { $x = new M(); $x->m = new D('cm'); $f = function () use ($x) { return $x->m; }; $v = $f(); $x->m = null; echo "read ", $v->n, "\n"; unset($v); echo "v gone\n"; }
closureRead();
echo "done\n";

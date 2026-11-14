<?php
// A collected cycle through untyped props, a closure and an array prop: every garbage node's members are released before any node is freed (ASan: MANTICORE_ASAN=1 MANTICORE_POOL=0 with an ASan stdlib).
class Node { public $other; public $name; public function __construct(public int $id) { $this->name = 'n' . $id; } }
class Holder { public $fn; public $peer; public $list = []; }
function plain(int $n): void {
    for ($i = 0; $i < $n; $i++) { $a = new Node($i); $b = new Node($i + 1); $a->other = $b; $b->other = $a; }
}
function viaClosure(int $n): void {
    for ($i = 0; $i < $n; $i++) {
        $h = new Holder(); $p = new Holder(); $h->peer = $p; $p->peer = $h;
        $h->fn = fn () => $p; $p->list[] = 'x' . $i;
    }
}
plain(2000);
var_dump(gc_collect_cycles() >= 4000);
viaClosure(2000);
var_dump(gc_collect_cycles() > 0);
echo "end\n";

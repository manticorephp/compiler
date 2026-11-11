<?php
class Node { public $other; }
function makeCycles(int $n): void {
    for ($i = 0; $i < $n; $i++) {
        $a = new Node; $b = new Node;
        $a->other = $b; $b->other = $a;
    }
}
var_dump(gc_enabled());
gc_disable();
var_dump(gc_enabled());
makeCycles(25000);
var_dump(gc_collect_cycles() > 0);
gc_enable();
var_dump(gc_enabled());
gc_disable();
gc_disable();
var_dump(gc_enabled());
gc_enable();
var_dump(gc_enabled());
makeCycles(25000);
var_dump(gc_collect_cycles() >= 0);

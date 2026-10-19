<?php
// A property handed by-ref to a callee that keeps a reference shares the property's storage.
class B { public array $r = []; function bind(mixed &$v): void { $this->r[] = &$v; } }
class P { public $m = 5; public mixed $n = "s"; }
$b = new B; $p = new P;
$b->bind($p->m);
echo $p->m, "\n";
$p->m = 6;
echo $p->m, " ", $b->r[0], "\n";
$b->r[0] = 7;
echo $p->m, "\n";
$b->bind($p->n);
$p->n = "t";
echo $b->r[1], "\n";
$b->r[1] = "u";
echo $p->n, "\n";

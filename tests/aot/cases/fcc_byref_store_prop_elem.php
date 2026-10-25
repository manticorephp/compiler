<?php
// A first-class callable storing its by-ref argument keeps a reference to a property or element argument.
class H { public array $r = []; function keep(mixed &$v) { $this->r[] = &$v; } }
class O { public $q = 1; }
function t(): void { $o = new O; $h = new H; $f = $h->keep(...); $f($o->q); $o->q = 4; echo $h->r[0], "\n"; $a = [1, 2]; $f($a[1]); $a[1] = 7; echo $h->r[1], "\n"; }
t();
$o = new O; $h = new H; $f = $h->keep(...); $f($o->q); $o->q = 5; echo $h->r[0], "\n";

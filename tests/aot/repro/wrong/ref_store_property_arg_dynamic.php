<?php
// A typed or dynamically-called property passed by reference to a storing callee is stored as a stale copy.
// issue: #353
class H { public array $r = []; function keep(mixed &$v) { $this->r[] = &$v; } }
class O { public int $x = 1; public $q = 1; }
$h = new H; $o = new O; $h->keep($o->x); $o->x = 2; echo $h->r[0], "\n";
$h2 = new H; $m = 'keep'; $h2->$m($o->q); $o->q = 3; echo $h2->r[0], "\n";
$h3 = new H; $n = 'q'; $f = $h3->keep(...); $f($o->$n); $o->q = 4; echo $h3->r[0], "\n";

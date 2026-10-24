<?php
class H { public array $r = []; function keep(mixed &$v) { $this->r[] = &$v; } }
class O { public $q = 1; }
$o = new O;
$h = new H; $h->keep($o->q); $o->q = 2; echo $h->r[0], "\n";
$h2 = new H; $m = 'keep'; $h2->$m($o->q); $o->q = 3; echo $h2->r[0], "\n";

<?php
// A dynamically-named property passed by reference to a storing first-class callable is stored as a stale copy.
// issue: #353
class H { public array $r = []; function keep(mixed &$v) { $this->r[] = &$v; } }
class O { public $q = 1; }
$o = new O;
$h3 = new H; $n = 'q'; $f = $h3->keep(...); $f($o->$n); $o->q = 4; echo $h3->r[0], "\n";

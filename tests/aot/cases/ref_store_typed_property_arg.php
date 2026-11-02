<?php
class H { public array $r = []; function keep(mixed &$v) { $this->r[] = &$v; } }
class O { public int $x = 1; public string $s = 'a'; public $q = 1; }
$h = new H; $o = new O;
$h->keep($o->x); $o->x = 2; echo $h->r[0], "\n";
$h->r[0] = 5; echo $o->x, "\n";
$h->keep($o->s); $o->s = 'b'; echo $h->r[1], "\n";
$h2 = new H; $m = 'keep'; $h2->$m($o->q); $o->q = 3; echo $h2->r[0], "\n";
$o->x = 7; echo $o->x + 1, "\n";
try { $o->x = 'nope'; } catch (TypeError $e) { echo "TypeError\n"; }
class P { public int $n = 1; }
$p = new P; $keep = [&$p->n];
try { $p->n = 'nope'; echo "no\n"; } catch (TypeError $e) { echo get_class($e), "\n"; }
$p->n = '42'; echo $keep[0] + 1, "\n";

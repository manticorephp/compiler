<?php
// A generator local handed by-ref to a callee that keeps a reference stays bound across yields.
class H { public array $r = []; function bind(mixed &$v): void { $this->r[] = &$v; } }
function gen(H $h) { $v = "g1"; $h->bind($v); yield 1; $v = "g2"; yield 2; $h->r[0] = "g3"; echo $v, "\n"; yield 3; }
$h = new H;
foreach (gen($h) as $x) { echo $x, " ", $h->r[0], "\n"; }
echo $h->r[0], "\n";

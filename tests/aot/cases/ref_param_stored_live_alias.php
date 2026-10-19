<?php
class H { public array $refs = []; function bind(int $k, mixed &$v): void { $this->refs[$k] = &$v; } function fwd(int $k, mixed &$v): void { $this->bind($k, $v); } }
function mk(): H { $h = new H; $a = "x"; $h->bind(1, $a); $a = "y"; echo $h->refs[1], "\n"; $h->refs[1] = "z"; echo $a, "\n"; return $h; }
$h = mk(); $h->refs[1] = "w"; echo $h->refs[1], "\n";
function g(H $h) { $v = 1; $h->bind(2, $v); $h->fwd(3, $v); $v = 7; echo $h->refs[2], $h->refs[3], "\n"; }
g($h); $h->refs[2] = 9; echo $h->refs[3], "\n";

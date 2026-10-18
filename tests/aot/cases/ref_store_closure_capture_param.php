<?php
// A closure capturing a by-ref param by reference and outliving the call still sees the caller's variable.
class S { public $cb; function set(mixed &$v): void { $this->cb = function () use (&$v) { return $v; }; } }
function mk(S $s): void { $x = "r"; $s->set($x); echo ($s->cb)(), "\n"; $x = "q"; echo ($s->cb)(), "\n"; }
$s = new S;
mk($s);
echo ($s->cb)(), "\n";
function bump(int &$n): void { $inc = function () use (&$n) { $n++; }; $inc(); $inc(); }
$c = 1; bump($c); echo $c, "\n";
class W { public int $c = 10; }
$w = new W; bump($w->c); echo $w->c, "\n";

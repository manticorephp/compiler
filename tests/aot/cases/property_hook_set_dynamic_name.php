<?php
// A set hook of a virtual property runs for a dynamic-name write and an erased receiver, not only a typed static write.
class R { public int $n { get => 1; set { throw new Error('ro n'); } } public int $k = 0; }
class Q { public int $n = 0; }
function mk(bool $b): R|false { return $b ? new R() : false; }
function at(callable $f) { try { $f(); echo "no error\n"; } catch (Error $e) { echo $e->getMessage(), "\n"; } }
$r = mk(true);
$p = 'n';
at(function () use ($r, $p) { $r->$p = 1; });
at(function () use ($r) { $r->n = 1; });
$x = new R();
at(function () use ($x, $p) { $x->$p = 1; });
at(fn() => $x->$p = 2);
function viaMixed(mixed $o, string $p) { $o->$p = 3; }
at(fn() => viaMixed(new R(), 'n'));
at(fn() => viaMixed(new Q(), 'n'));
function viaMixed2(mixed $o) { $o->n = 3; }
at(fn() => viaMixed2(new R()));

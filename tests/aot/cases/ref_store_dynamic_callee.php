<?php
// A by-ref arg to a closure, callable, function name or dynamic method that keeps a reference.
$store = [];
function viaClo(callable $c) { $i = 41; $c($i); echo $i + 1, "\n"; $i = 99; }
function viaName(string $fn) { $s = "a"; $fn($s); echo $s, "\n"; $s = "b"; }
function viaDyn(object $o, string $m) { $x = 1.5; $o->$m($x); echo $x, "\n"; $x = 2.5; }
function keepGlobal(mixed &$v): void { global $kept; $kept[] = &$v; }
class K { public array $r = []; function hold(mixed &$v): void { $this->r[] = &$v; } }
$f = function (mixed &$v) use (&$store) { $store[] = &$v; };
viaClo($f);
var_dump($store);
$kept = [];
viaName('keepGlobal');
var_dump($kept);
$k = new K;
viaDyn($k, 'hold');
var_dump($k->r);

<?php

/*
 * `...$arr` against a callee's real signature. The pack's LENGTH is a run-time
 * property, so every parameter it does not reach takes that callee's own
 * default — and for an erased receiver "that callee" is the arm the class_id
 * picked, not whichever class happened to declare the method first.
 */

class R
{
    public $id;
    public function __construct(public string $t = '-', public int $n = 9) {}
    public function label(): string { return $this->t . $this->n; }
}

class S
{
    public static function mk(string $t = 'x', int $n = 1): string { return $t . $n; }
}

interface I { public function go(string $a = 'A', int $b = 2): string; }
class P implements I { public function go(string $a = 'A', int $b = 2): string { return 'P' . $a . $b; } }
class Q implements I { public function go(string $a = 'z', int $b = 7): string { return 'Q' . $a . $b; } }

function add($a, $b, $c) { return $a + $b + $c; }

$cls = 'R';
$one = ['C'];
$two = ['C', 5];
$none = [];

// dynamic new: the pack, not the class name, is the argument list
echo (new $cls(...$one))->label(), "\n";
echo (new $cls(...$two))->label(), "\n";
echo (new $cls(...$none))->label(), "\n";

// static class name, same shapes
echo (new R(...$one))->label(), "\n";
echo (new R(...$none))->label(), "\n";

// explicit __construct on an erased receiver
$e = new $cls();
$e->__construct(...$one);
echo $e->label(), "\n";

// static method
echo S::mk(...$one), ' ', S::mk(...$two), ' ', S::mk(...$none), "\n";

// plain function, exact arity
echo add(...[1, 2, 3]), "\n";

// erased receiver whose arms declare DIFFERENT defaults
/** @var array<int,I> */
$objs = [new P(), new Q()];
foreach ($objs as $o) { echo $o->go(...$one), ' ', $o->go(...$none), "\n"; }

// a mixed-element pack: the elements are cells, the params are not
class M { public function __construct(public string $s = '', public int $i = 0, public float $f = 0.0) {} }
$mixed = ['a', 3, 1.5];
$m = new M(...$mixed);
echo $m->s, ' ', $m->i, ' ', $m->f, "\n";
$m2 = new M(...['b']);
echo $m2->s, ' ', $m2->i, ' ', $m2->f, "\n";

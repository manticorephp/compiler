<?php
// A closure copied into another local inside a callee (`$fn = $s`, what
// Closure::fromCallable() of a closure is): the copy's reference is given back,
// so the env — and what it captured — goes when the last holder does.
final class D { public function __construct(public string $n) {} public function __destruct() { echo "~", $this->n, "\n"; } }
function viaAlias(callable $s): int { $fn = $s; return $fn(); }
function viaFrom(callable $s): int { $fn = \Closure::fromCallable($s); return $fn(); }
function wrap(callable $s): \Closure { $fn = \Closure::fromCallable($s); return function () use ($fn): int { return $fn() + 1; }; }
function run(string $tag): void
{
    $d = new D($tag);
    $f = function () use ($d): int { return strlen($d->n); };
    unset($d);
    echo viaAlias($f), ' ', viaFrom($f), "\n";
    $w = wrap($f);
    echo $w(), "\n";
    unset($f);
    echo "f gone\n";
    unset($w);
    echo "w gone\n";
}
run('one'); run('three');

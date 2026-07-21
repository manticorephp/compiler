<?php

// A self-referential loop accumulator: the else-arm calls a method ON the
// accumulator. Round 1 that receiver is still unknown, so the ternary join
// must defer to the concrete then-arm (obj) instead of collapsing to unknown —
// otherwise nullLoopLocals re-seeds `acc` unknown forever and a `mixed` return
// reports gettype "integer".

final class Bag {
    public function __construct(public string $s) {}
    public function merge(Bag $o): Bag { return new Bag($this->s . "+" . $o->s); }
}

function mergeAll(array $xs): mixed {
    $acc = null;
    foreach ($xs as $x) { $acc = $acc === null ? $x : $acc->merge($x); }
    return $acc;
}

// Non-null seed, self-ref method on a numeric-carrying class.
final class Sum {
    public function __construct(public int $n) {}
    public function add(Sum $o): Sum { return new Sum($this->n + $o->n); }
}

function total(array $xs): mixed {
    $acc = new Sum(0);
    foreach ($xs as $x) { $acc = $acc->add($x); }
    return $acc;
}

var_dump(gettype(mergeAll([])));
var_dump(gettype(mergeAll([new Bag("x")])));
var_dump(gettype(mergeAll([new Bag("x"), new Bag("y"), new Bag("z")])));
var_dump(mergeAll([new Bag("x"), new Bag("y"), new Bag("z")])->s);

var_dump(gettype(total([new Sum(1), new Sum(2), new Sum(3)])));
var_dump(total([new Sum(1), new Sum(2), new Sum(3)])->n);

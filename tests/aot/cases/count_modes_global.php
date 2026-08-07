<?php

// The global-namespace half of the count() mode coverage: here the rewrite in
// LowerExprs DOES fire, so this is what exercises the runtime-mode arm and the
// Countable receiver. (The namespaced half is count_recursive_modes.php.)

class Bag implements Countable
{
    private array $items;

    public function __construct(array $items) { $this->items = $items; }

    public function count(): int { return count($this->items); }
}

function pick(bool $yes): mixed
{
    if ($yes) { return new Bag([1, 2, 3]); }
    return [[1, 2], [3, [4, 5]]];
}

$deep = [1, [2, [3, [4]]]];
$nested = [[1, 2], [3]];

var_dump(count($deep, COUNT_RECURSIVE));
var_dump(count($deep, COUNT_NORMAL));

// A RUNTIME mode: neither arm may be assumed at compile time.
$m = COUNT_NORMAL;
var_dump(count($deep, $m));
var_dump(sizeof($nested, $m));
$m = COUNT_RECURSIVE;
var_dump(count($deep, $m));
var_dump(sizeof($nested, $m));
foreach ([COUNT_NORMAL, COUNT_RECURSIVE] as $mode) {
    var_dump(count($nested, $mode));
}

// Countable: php ignores the mode and answers ->count() for every one of them.
$bag = new Bag([1, 2, 3]);
var_dump(count($bag));
var_dump(count($bag, COUNT_NORMAL));
var_dump(count($bag, COUNT_RECURSIVE));
var_dump(count($bag, $m));

// The same receiver ERASED — the tag decides at runtime.
$e = pick(true);
var_dump(count($e, COUNT_RECURSIVE));
var_dump(count($e, $m));
$e = pick(false);
var_dump(count($e, COUNT_RECURSIVE));
var_dump(count($e, $m));
var_dump(count($e, COUNT_NORMAL));

// Tombstones at depth.
$inner = [1, 2, 3];
unset($inner[0]);
$outer = [$inner, [9]];
unset($outer[1]);
var_dump(count($outer, COUNT_RECURSIVE));

<?php

namespace App;

class Bag implements \Countable
{
    private array $items;

    public function __construct(array $items) { $this->items = $items; }

    public function count(): int { return \count($this->items); }
}

function pick(bool $yes): mixed
{
    if ($yes) { return new Bag([1, 2, 3]); }
    return [[1, 2], [3, [4, 5]]];
}

$nested = [[1, 2], [3]];
$deep = [1, [2, [3, [4]]]];

// The unqualified name inside a namespace resolves to `App\count`.
var_dump(count($nested, COUNT_RECURSIVE));
var_dump(count($nested, COUNT_NORMAL));
var_dump(count($nested));
var_dump(count($deep, COUNT_RECURSIVE));
var_dump(count([], COUNT_RECURSIVE));
var_dump(count([[], [[]]], COUNT_RECURSIVE));

// Fully qualified + the sizeof alias.
var_dump(\count($nested, COUNT_RECURSIVE));
var_dump(sizeof($deep, COUNT_RECURSIVE));
var_dump(\sizeof($deep, COUNT_RECURSIVE));

// A RUNTIME mode — the literal fold cannot see through the variable.
$m = COUNT_NORMAL;
var_dump(count($deep, $m));
$m = COUNT_RECURSIVE;
var_dump(count($deep, $m));
$modes = [COUNT_NORMAL, COUNT_RECURSIVE];
foreach ($modes as $mode) { var_dump(count($nested, $mode)); }

// Tombstones: the live count, not the physical length, at every depth.
$holes = [[1, 2, 3], [4, 5]];
unset($holes[1]);
var_dump(count($holes, COUNT_RECURSIVE));
$inner = [1, 2, 3];
unset($inner[0]);
$outer = [$inner, [9]];
var_dump(count($outer, COUNT_RECURSIVE));

// Countable: php ignores the mode entirely and returns ->count().
$bag = new Bag([1, 2, 3]);
var_dump(count($bag));
var_dump(count($bag, COUNT_NORMAL));
var_dump(count($bag, COUNT_RECURSIVE));
var_dump(count($bag, $m));

// The same receiver ERASED — the static type is gone, the tag decides.
$e = pick(true);
var_dump(count($e, COUNT_RECURSIVE));
var_dump(count($e, $m));
$e = pick(false);
var_dump(count($e, COUNT_RECURSIVE));
var_dump(count($e, $m));
var_dump(count($e, COUNT_NORMAL));

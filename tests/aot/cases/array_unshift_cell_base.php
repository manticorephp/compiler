<?php
// An ERASED base (`mixed`) carries its buffer NaN-boxed and reads its elements
// by TAG: the prepended values must be boxed, and the relocated buffer must go
// back into the slot BOXED too. Storing the raw word there read back as a
// denormal double.
function frontPush(mixed $m, mixed $v): mixed
{
    array_unshift($m, $v);
    return $m;
}

$a = frontPush(["z"], "a");
echo implode(",", $a), "\n";

$b = frontPush([2, 3], 1);
echo implode(",", $b), "\n";
var_dump($b[0], $b[1]);

function frontPushMany(mixed $m): mixed
{
    array_unshift($m, 1, "two", 3.5, true);
    return $m;
}

$c = frontPushMany(["tail"]);
var_dump($c[0], $c[1], $c[2], $c[3], $c[4]);
echo count($c), "\n";

class Bag
{
    /** @var mixed */
    public $items = [];
}

$bag = new Bag();
$bag->items = ["last"];
array_unshift($bag->items, "first");
echo implode(",", $bag->items), "\n";
var_dump($bag->items[0]);

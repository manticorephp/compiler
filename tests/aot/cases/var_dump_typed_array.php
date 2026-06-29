<?php
/** @param int[] $a */
function dumpInts(array $a): void { var_dump($a); }
/** @param string[] $a */
function dumpStrs(?array $a): void { var_dump($a); }
/** @param array<string,int> $a */
function dumpAssoc(array $a): void { var_dump($a); }
dumpInts([1, 2, 3]);
dumpStrs(["x", "y"]);
dumpAssoc(["a" => 1, "b" => 2]);

class Bag {
    /** @var int[] */
    public array $items = [];
}
$b = new Bag();
$b->items = [10, 20];
var_dump($b->items);

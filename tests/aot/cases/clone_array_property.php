<?php
// PHP arrays are values: `clone` must COPY each array-typed property, not co-own
// the handle — a mutation on the clone must not alias the original's buffer.
class Bag {
    public int $v = 1;
    /** @var string[] */ public array $items = [];
    /** @var array<string,int> */ public array $counts = [];
}
$a = new Bag();
$a->v = 5;
$a->items[] = "x";
$a->counts['k'] = 1;

$b = clone $a;
$b->v = 9;
$b->items[] = "y";
$b->counts['k'] = 99;
$b->counts['n'] = 7;

echo $a->v, " ", $b->v, "\n";                          // 5 9
echo count($a->items), " ", count($b->items), "\n";    // 1 2
echo implode(",", $a->items), " | ", implode(",", $b->items), "\n"; // x | x,y
echo $a->counts['k'], " ", $b->counts['k'], "\n";      // 1 99
echo count($a->counts), " ", count($b->counts), "\n";  // 1 2

<?php
// Countable: count($obj) dispatches to $obj->count().
class Bag implements Countable {
    /** @var int[] */
    private array $items = [];
    public function add(int $x): void { $this->items[] = $x; }
    public function count(): int { return count($this->items); }
}
$b = new Bag();
echo count($b), "\n";
$b->add(1);
$b->add(2);
$b->add(3);
echo count($b), "\n";

// iterable type hint over an array.
function total(iterable $xs): int { $s = 0; foreach ($xs as $x) { $s = $s + $x; } return $s; }
echo total([1, 2, 3, 4]), "\n";
echo total([]), "\n";

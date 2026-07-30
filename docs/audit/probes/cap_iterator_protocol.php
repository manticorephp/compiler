<?php
// @epic: iteration
// @why: symfony's Finder, the event dispatcher's listener lists and doctrine's
//       collections are all IteratorAggregate returning generators; twig compiles
//       {% for %} onto the same protocol. Memory notes an open SIGSEGV with two
//       erased-iterator loops over one class, so this pins the shape.

final class CapBag implements IteratorAggregate, Countable
{
    /** @var array<string,int> */
    private array $items = ['a' => 1, 'b' => 2, 'c' => 3];
    public function getIterator(): Generator { yield from $this->items; }
    public function count(): int { return count($this->items); }
}

$bag = new CapBag();
foreach ($bag as $k => $v) { echo "1:$k=$v\n"; }
foreach ($bag as $k => $v) { echo "2:$k=$v\n"; }
var_dump(count($bag));
var_dump(iterator_to_array($bag));
var_dump($bag instanceof Traversable);

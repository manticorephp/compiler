<?php
// A Traversable spread into an array literal: a generator, an Iterator, an
// IteratorAggregate — string keys kept, int keys renumbered (php 8.1).
function gs() { yield 'x'; yield 'y'; }
var_dump([1, ...gs()]);
var_dump([null, ...new ArrayIterator(['w', 'v'])]);
function gk(): Generator { yield 'a' => 1; yield 5 => 2; yield 5 => 3; yield 'a' => 4; }
var_dump(['z', ...gk()]);
final class Bag implements IteratorAggregate {
    public function getIterator(): Iterator { return new ArrayIterator(['p' => 'q', 7 => 'r']); }
}
var_dump([...new Bag(), 's']);
$n = [...gs(), ...[3, 4], ...gs()];
echo count($n), ' ', implode(',', $n), "\n";

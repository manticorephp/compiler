<?php
// is_iterable / is_countable answer for objects too: Twig's ensureTraversable
// turned every iterator into [] and a page rendered its empty branch.
function g(): \Generator { yield 1; }
class M implements IteratorAggregate { public function getIterator(): \Traversable { return new ArrayIterator([1]); } }
class C implements Countable { public function count(): int { return 3; } }
function ensure(mixed $s): iterable { return is_iterable($s) ? $s : []; }

$values = [new ArrayIterator([1]), new M, g(), new ArrayObject([]), new C, new stdClass, [], [1], 1, 's', null, function () {}];
foreach ($values as $i => $v) {
    echo $i, " ", var_export(is_iterable($v), true), ' ', var_export(is_countable($v), true), "\n";
}
echo get_debug_type(ensure(new ArrayIterator([1]))), ' ', get_debug_type(ensure(3)), "\n";
foreach (ensure(new ArrayIterator(['a', 'b'])) as $x) { echo $x; }
echo "\n";

<?php
// A yielding foreach over an erased base walks whatever arrives: only arrays did,
// so a Twig template's loop over an ArrayIterator rendered its empty branch.
class Agg implements IteratorAggregate { public function getIterator(): \Traversable { return new ArrayIterator(['x' => 1, 'y' => 2]); } }
class Plain { public int $a = 1; public string $b = 'two'; private int $hidden = 3; }
function pass($s) { return $s; }
function walk(mixed $base) { foreach ($base as $k => $v) { yield "$k=" . json_encode($v); } yield 'end'; }
function gen() { yield 'g' => 7; yield 'h' => [8]; }
function ctx(array $context) {
    $context['_seq'] = pass($context['in']);
    $context['_iterated'] = false;
    foreach ($context['_seq'] as $context['_key'] => $context['post']) {
        yield $context['_key'] . ':' . json_encode($context['post']);
        $context['_iterated'] = true;
    }
    if (!$context['_iterated']) { yield 'EMPTY'; }
}
function nested(mixed $a, mixed $b) { foreach ($a as $x) { foreach ($b as $y) { yield "$x$y"; } } }
function early(mixed $a) { foreach ($a as $x) { if ($x === 2) { break; } yield $x; } yield 'done'; }
function untyped(array $items) { foreach ($items as $i => $item) { yield $i => $item * 2; } }

foreach ([[1, 'k' => 'v'], new ArrayIterator(['a', 'b']), new Agg, gen(), new Plain, [], null, 5] as $base) {
    echo implode(' ', iterator_to_array(walk($base), false)), "\n";
}
foreach ([new ArrayIterator([[1]]), [['p' => 1]], new ArrayIterator([])] as $in) {
    echo implode(' ', iterator_to_array(ctx(['in' => $in]), false)), "\n";
}
echo implode(' ', iterator_to_array(nested(new ArrayIterator([1, 2]), ['a', 'b']), false)), "\n";
echo implode(' ', iterator_to_array(early(new ArrayIterator([1, 2, 3])), false)), "\n";
foreach (untyped([3, 'q' => 4]) as $k => $v) { echo $k, '=', $v, "\n"; }

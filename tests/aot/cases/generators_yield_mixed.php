<?php
// A generator whose yields have no single static type: unionTypes collapses the
// element to CELL, so the consumer's value var IS cell-typed while the producer
// stored raw. That is the exact disagreement the shallow box closes — the value
// keeps its payload, only the tag is added.

class Point
{
    public function __construct(public int $x, public int $y) {}
}

function mixedBag(): Generator
{
    yield 'text';
    yield 42;
    yield 3.5;
    yield true;
    yield null;
    yield ['a', 'b'];
    yield new Point(7, 9);
}

foreach (mixedBag() as $k => $v) {
    echo $k, ': ';
    if (is_array($v)) {
        echo 'array(', count($v), ') ', implode('|', $v);
    } elseif (is_object($v)) {
        echo 'object ', $v->x, ',', $v->y;
    } elseif (is_null($v)) {
        echo 'null';
    } elseif (is_bool($v)) {
        echo 'bool ', $v ? 'true' : 'false';
    } elseif (is_int($v)) {
        echo 'int ', $v;
    } elseif (is_float($v)) {
        echo 'float ', $v;
    } else {
        echo 'string ', $v, ' len=', strlen($v);
    }
    echo "\n";
}

// The method protocol reads the same slot.
$g = mixedBag();
echo 'current=';
var_dump($g->current());
$g->next();
echo 'after next=';
var_dump($g->current());
echo 'key=';
var_dump($g->key());
echo 'valid=';
var_dump($g->valid());

// iterator_to_array drains it through the prelude, whose accumulator is
// cell-typed and now receives real cells. (The object is dropped first — php
// numbers object handles over every object ever made, which manticore does not
// reproduce, and `#N` in the dump would be about that, not about the repr.)
$drained = iterator_to_array(mixedBag());
array_pop($drained);
var_dump($drained);

// send() returns the NEW current through the same channel.
function echoer(): Generator
{
    $a = yield 'first';
    echo 'got ', $a, "\n";
    yield 'second';
}
$e = echoer();
echo $e->current(), "\n";
echo $e->send('sent'), "\n";
echo "done\n";

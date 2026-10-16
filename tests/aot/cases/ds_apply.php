<?php
use Manticore\Ds\{Int8Array, Int32Array, Float32Array, BitArray};

$a = Int32Array::fromArray([1, 2, 3, 4]);
$a->apply(fn(int $v, int $i): int => $v * 10 + $i);
echo implode(',', $a->toArray()), "\n";

$f = Float32Array::fromArray([1, 2]);
$f->apply(fn($v) => $v / 4);
echo implode(',', $f->toArray()), "\n";

$b = BitArray::fromArray([true, false, true]);
$b->apply(fn($v) => !$v);
echo implode(',', array_map('intval', $b->toArray())), "\n";

$n = 0;
$a->apply(function (int $v) use (&$n) { $n++; return $v; });
echo $n, "\n";

$s = Int8Array::fromArray([100, 20]);
try {
    $s->apply(fn(int $v): int => $v * 2);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
echo implode(',', $s->toArray()), "\n";

try {
    $s->apply(fn($v) => 'x');
} catch (TypeError $e) {
    echo get_class($e), "\n";
}

$e = new Int32Array(0);
$e->apply(fn($v) => $v + 1);
echo count($e), "\n";

$g = Int32Array::fromArray([1, 2, 3, 4]);
$seen = 0;
try {
    $g->apply(function (int $v) use ($g, &$seen) { $seen++; if ($seen === 1) { $g->pop(); $g->pop(); } return $v; });
} catch (OutOfBoundsException $x) {
    echo get_class($x), "\n";
}
echo $seen, ' ', count($g), "\n";

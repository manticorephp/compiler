<?php
use Manticore\Ds\Int8Array;
use Manticore\Ds\Int32Array;
use Manticore\Ds\Float32Array;
use Manticore\Ds\Float64Array;
use Manticore\Ds\BitArray;

function attempt(callable $f): void
{
    try { $f(); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

$a = new Int32Array();
for ($i = 0; $i < 10; $i++) { $a[] = $i * $i; }
$a->push(-7);
echo count($a), ' ', json_encode($a), "\n";
echo $a->pop(), ' ', $a->pop(), ' ', count($a), "\n";
var_dump($a->pop());

// a value that does not fit leaves the size alone
$b = new Int8Array(2);
attempt(function () use ($b) { $b[] = 300; });
attempt(function () use ($b) { $b->push('abc'); });
$b[] = '12';
$b[] = true;
echo count($b), ' ', json_encode($b), "\n";

$f = new Float32Array();
$f[] = 0.1;
$f[] = 2;
var_dump($f->pop(), $f->pop());
attempt(function () use ($f) { $f->pop(); });

$d = Float64Array::fromArray([1.5]);
$d[] = 2.5;
echo json_encode($d), ' ', $d->pop() + $d->pop(), "\n";

$bits = new BitArray();
for ($i = 0; $i < 70; $i++) { $bits[] = ($i % 7) === 0; }
$on = 0;
foreach ($bits as $bit) { if ($bit) { $on++; } }
echo count($bits), ' ', $on, ' ';
var_dump($bits->pop(), $bits->pop());
attempt(function () { (new BitArray())->pop(); });

// grows through many reallocations, shrinks back, grows again
$g = new Int32Array();
$sum = 0;
for ($i = 0; $i < 100000; $i++) { $g[] = $i; }
while (count($g) > 10) { $sum += $g->pop(); }
for ($i = 0; $i < 5; $i++) { $g[] = -$i; }
echo count($g), ' ', $sum, ' ', json_encode($g), "\n";

// a clone grows on its own
$h = clone $g;
$h[] = 99;
echo count($g), ' ', count($h), ' ', $h[15], "\n";

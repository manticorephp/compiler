<?php
use Manticore\Ds\Int8Array;
use Manticore\Ds\Int32Array;
use Manticore\Ds\Int64Array;
use Manticore\Ds\UInt8Array;
use Manticore\Ds\UInt32Array;
use Manticore\Ds\Float32Array;
use Manticore\Ds\Float64Array;
use Manticore\Ds\BitArray;

function attempt(callable $f): void
{
    try { var_dump($f()); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

$a = Int32Array::fromArray([5, -3, 8, 0, 2147483647, -2147483648]);
var_dump($a->sum(), $a->min(), $a->max());
$b = Int8Array::fromArray([-128, 127, 5]);
var_dump($b->sum(), $b->min(), $b->max());
$u = UInt32Array::fromArray([4294967295, 1, 7]);
var_dump($u->sum(), $u->min(), $u->max());
$u8 = UInt8Array::fromArray([255, 0, 200]);
var_dump($u8->sum(), $u8->min(), $u8->max());
$l = Int64Array::fromArray([PHP_INT_MAX, -5, PHP_INT_MIN + 10]);
var_dump($l->sum(), $l->min(), $l->max());

$f = Float64Array::fromArray([1.5, -2.25, 1e10, 0.0]);
var_dump($f->sum(), $f->min(), $f->max());
$g = Float32Array::fromArray([0.5, 0.25, -8]);
var_dump($g->sum(), $g->min(), $g->max());

$bits = new BitArray(130);
for ($i = 0; $i < 130; $i += 3) { $bits[$i] = true; }
var_dump($bits->sum());

var_dump((new Int32Array())->sum(), (new Float64Array(3))->sum(), (new BitArray())->sum());
attempt(fn () => (new Int32Array())->min());
attempt(fn () => (new Float64Array())->max());
attempt(fn () => (new Int8Array(1))->max());

$c = clone $a;
var_dump($a->equals($c), $c->equals($a));
$c[2] = 9;
var_dump($a->equals($c));
$c[2] = 8;
$c[] = 1;
var_dump($a->equals($c));
var_dump(Int8Array::fromArray([1, 2])->equals(UInt8Array::fromArray([1, 2])));
var_dump(Float64Array::fromArray([1.5, 2.0])->equals(Float64Array::fromArray([1.5, 2.0])));
var_dump(Float64Array::fromArray([NAN])->equals(Float64Array::fromArray([NAN])));
$b1 = new BitArray(70); $b2 = new BitArray(70);
$b1[69] = true;
var_dump($b1->equals($b2));
$b2[69] = true;
var_dump($b1->equals($b2), (new Int32Array())->equals(new Int32Array()));

// the range rule still reads its bounds
attempt(function () use ($b) { $b[0] = 300; return 'no'; });

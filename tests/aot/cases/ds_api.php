<?php
use Manticore\Ds\Int32Array;
use Manticore\Ds\Float64Array;
use Manticore\Ds\BitArray;
use Manticore\Ds\UInt8Array;

function show(string $label, Manticore\Ds\TypedArray $a): void
{
    echo $label, ' [', count($a), '] ', json_encode($a->toArray()), "\n";
}
function attempt(callable $f): void
{
    try { $f(); echo "no exception\n"; }
    catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

$a = new Int32Array(4);
$a[0] = 10; $a[1] = -20; $a[3] = 2147483647;
show('set', $a);
echo $a[1] + $a[3], ' ', $a->getSize(), ' ', count($a), "\n";
$a->setSize(6); show('grow', $a);
$a->setSize(2); show('shrink', $a);
$a->setSize(5); show('regrow', $a);
$a->insert(1, 2, 7); show('insert', $a);
$a->insert(7, 1); show('append', $a);
$a->insert(0, 0, 9); show('insert0', $a);
$a->remove(0, 1); show('remove', $a);
$a->remove(1, 3); show('remove3', $a);
$a->fill(5); show('fill', $a);
$a->fill(-1, 1, 3); show('fillrange', $a);
echo $a->indexOf(-1), ' ', $a->indexOf(-1, 2), ' ', $a->indexOf(5, 1), ' ', $a->indexOf(99), ' ', $a->indexOf(1 << 40), "\n";

$b = Int32Array::fromArray(['x' => 1, 5 => 2, 3]);
show('fromArray', $b);
$a->copyFrom($b, 1, 0, 2); show('copyFrom', $a);
$b->copyFrom($b, 0, 1, 2); show('overlap', $b);

var_dump(isset($a[0]), isset($a[3]), isset($a[4]), isset($a[-1]), isset($a['1']), isset($a['x']), empty($a[1]));
unset($a[0]); show('unset', $a);
foreach ($a as $k => $v) { echo $k, '=', $v, ' '; }
echo "\n";
$n = 0;
foreach ($b as $v) { $n += $v; }
echo $n, "\n";
echo get_class($a->getIterator() instanceof \Traversable ? $a : $b), "\n";

$f = new Float64Array(3);
$f[0] = 1.5; $f[1] = 2; $f[2] = '0.25';
show('float', $f);
var_dump($f[1], $f[0] * $f[2]);
$f->insert(1, 1, 9.75); show('finsert', $f);
echo $f->indexOf(9.75), ' ', $f->indexOf(3), "\n";

$bits = new BitArray(70);
$bits[0] = true; $bits[64] = 1; $bits[69] = 'yes';
var_dump($bits[0], $bits[1], $bits[64]);
echo $bits->indexOf(true, 1), ' ', $bits->indexOf(false), ' ', count($bits), "\n";
$bits->remove(0, 60); show('bits', $bits);
$bits->fill(true, 0, 3); $bits->insert(1, 2, false); show('bits2', $bits);

$u = UInt8Array::fromArray([1, 2, 3]);
$c = clone $u; $c[0] = 200; $u[1] = 100;
show('orig', $u); show('clone', $c);

attempt(fn () => $a[10]);
attempt(fn () => $a[-1]);
attempt(function () use ($a) { $a[4] = 1; });
attempt(fn () => $a['x']);
attempt(fn () => $a[null]);
attempt(fn () => $a[[1]]);
attempt(function () use ($a) { unset($a[9]); });
attempt(fn () => new Int32Array(-1));
attempt(fn () => $a->setSize(-2));
attempt(fn () => $a->insert(9, 1));
attempt(fn () => $a->insert(0, -1));
attempt(fn () => $a->remove(2, 9));
attempt(fn () => $a->fill(1, 2, 99));
attempt(fn () => $a->copyFrom($u, 0, 0, 1));
attempt(fn () => $a->copyFrom($b, 0, 3, 5));
echo $a['2'], ' ', $a[1.9], ' ', $a[true], "\n";
show('end', $a);

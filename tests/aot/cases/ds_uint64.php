<?php
// superset: no php oracle (the polyfill wraps past PHP_INT_MAX differently) — expected output is written by hand
use Manticore\Ds\UInt64Array;

#[TypeDef(repr: 'u64')]
final class Hash
{
    public function __construct(public readonly int $value) {}
    public function bucket(int $n): int { return (int)\Manticore\Ds\UInt64Array::toDecimal($this->value) % $n; }
    public function hex(): string { return sprintf('%016x', $this->value); }
}

function attempt(callable $f): void
{
    try { $f(); } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

$a = new UInt64Array(6);
$a[0] = 5;
$a[1] = '18446744073709551615';
$a[2] = '9223372036854775808';
$a[3] = 9223372036854775807;
$a[4] = 1.8446744073709552E+19 - 4096.0;
$a[5] = -1;
for ($i = 0; $i < 6; $i++) { echo $i, ' ', $a[$i], ' ', $a->getString($i), "\n"; }
echo $a->min(), ' ', $a->getString(0), ' | max ', UInt64Array::toDecimal($a->max()), ' | sum ', UInt64Array::toDecimal($a->sum()), "\n";
echo UInt64Array::compare(-1, 1), UInt64Array::compare(1, -1), UInt64Array::compare(7, 7), UInt64Array::compare(PHP_INT_MIN, PHP_INT_MAX), "\n";
echo $a->indexOf('18446744073709551615'), ' ', $a->indexOf(-1, 2), ' ', $a->indexOf(6), "\n";

$b = clone $a;
$b[0] = $a[1];
echo $b->getString(0), ' ', $b->equals($a) ? 'eq' : 'ne', "\n";
$b[] = '00042';
$b->push(true);
echo count($b), ' ', $b->pop(), ' ', $b->pop(), "\n";

attempt(function () use ($a) { $a[0] = '18446744073709551616'; });
attempt(function () use ($a) { $a[0] = '-1'; });
attempt(function () use ($a) { $a[0] = -0.5; });
attempt(function () use ($a) { $a[0] = 2.5; });
attempt(function () use ($a) { $a[0] = 1.8446744073709552E+19; });
attempt(function () use ($a) { $a[0] = 'abc'; });
attempt(function () use ($a) { $a[0] = [1]; });
attempt(fn () => (new UInt64Array())->min());
echo $a->getString(0), "\n";

// zero-extends nothing, truncates nothing: the inline loop copies bits
$c = new UInt64Array(1000);
for ($i = 0; $i < 1000; $i++) { $c[$i] = $i * 7919 * 104729 * 1299709 * 15485863; }
$x = 0;
for ($i = 0; $i < 1000; $i++) { $x ^= $c[$i]; }
echo UInt64Array::toDecimal($x), ' ', UInt64Array::toDecimal($c->max()), "\n";

/** @var UInt64Array<Hash> $h */
$h = new UInt64Array(2);
$h[0] = new Hash(-1);
$h[1] = new Hash(1 << 40);
echo $h[0]->hex(), ' ', $h[1]->hex(), ' ', $h[0]->bucket(1000), ' ', $h->pop()->hex(), "\n";

<?php

// The key an Iterator CLASS's key() answers is a +1, like current()'s: the loop
// key variable owns it. Bound as a borrow, every fresh string key an
// ArrayIterator handed out leaked — symfony's HeaderBag iteration, per request.
// memory_get_peak_usage() answers the peak RSS here. @serial: a memory measurement.

class Bag implements IteratorAggregate
{
    /** @var array<string, list<string>> */
    private array $h = [];

    public function set(string $k, string $v): void { $this->h[strtolower($k)] = [$v]; }

    public function getIterator(): ArrayIterator { return new ArrayIterator($this->h); }
}

$b = new Bag();
$b->set('Host', 'x');
$b->set('Accept', 'y');
foreach ($b as $k => $v) { echo $k, '=', $v[0], "\n"; }
echo $k, "\n";

$base = 0;
for ($i = 0; $i < 200000; $i++) {
    $a = [strtolower('HOST') => 1, strtolower('ACCEPT') => 2];
    $it = new ArrayIterator($a);
    foreach ($it as $k => $v) { }
    $b = new Bag();
    $b->set('User-Agent', 'z');
    foreach ($b as $k => $v) { $last = $k; }
    if ($i === 1000) { $base = memory_get_peak_usage(); }
}
echo $last, "\n";
echo memory_get_peak_usage() - $base < 3000000 ? "flat\n" : "grows\n";

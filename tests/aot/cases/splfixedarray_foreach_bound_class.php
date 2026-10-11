<?php
declare(strict_types=1);

final class Item
{
    public function __construct(public int $n) {}
    public function plus(int $d): int { return $this->n + $d; }
}

/** @extends SplFixedArray<Item> */
final class Items extends SplFixedArray {}

/** @extends SplFixedArray<Item> */
final class Reversed extends SplFixedArray
{
    public function getIterator(): Iterator { return new ArrayIterator(array_reverse($this->toArray())); }
}

function fill(int $n): Items
{
    $a = new Items($n);
    for ($i = 0; $i < $n; $i++) { $a[$i] = new Item($i * 10); }
    return $a;
}

$a = fill(4);
foreach ($a as $k => $it) {
    echo $k, '=', $it->n, '/', $it->plus(5), ' ';
}
echo "\n";

echo "-- mutate through the loop variable\n";
foreach ($a as $it) { $it->n = $it->n + 1; }
foreach ($a as $it) { echo $it->n, ' '; }
echo "\n";

echo "-- break / continue\n";
foreach ($a as $k => $it) {
    if ($k === 0) { continue; }
    if ($it->n > 20) { break; }
    echo $it->n, ' ';
}
echo "\n";

echo "-- loop variable keeps the last element\n";
foreach ($a as $last) {}
echo $last->n, "\n";

echo "-- grow while iterating\n";
$g = fill(2);
foreach ($g as $k => $it) {
    echo $it->n, ' ';
    if ($k === 0) { $g->setSize(3); $g[2] = new Item(77); }
}
echo "\n";

echo "-- nested\n";
$p = fill(2);
foreach ($p as $x) { foreach ($p as $y) { echo $x->n + $y->n, ' '; } }
echo "\n";

echo "-- generator body\n";
function gen(Items $a): Generator
{
    foreach ($a as $it) { yield $it->n; }
}
foreach (gen($a) as $v) { echo $v, ' '; }
echo "\n";

echo "-- getIterator override\n";
$r = new Reversed(3);
for ($i = 0; $i < 3; $i++) { $r[$i] = new Item($i); }
foreach ($r as $k => $it) { echo $k, ':', $it->n, ' '; }
echo "\n";

echo "-- object outlives the array\n";
function keep(): Item
{
    $arr = fill(3);
    foreach ($arr as $it) { $held = $it; }
    return $held;
}
$h = keep();
echo $h->n, ' ', $h->plus(1), "\n";

echo "-- the same object in two slots\n";
$s = new Items(2);
$one = new Item(1);
$s[0] = $one; $s[1] = $one;
foreach ($s as $it) { $it->n = $it->n + 10; }
echo $one->n, "\n";

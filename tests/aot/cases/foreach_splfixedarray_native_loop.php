<?php
declare(strict_types=1);

final class Box { public function __construct(public int $n) {} }

class Plain extends SplFixedArray {}

final class OverGet extends SplFixedArray
{
    public function offsetGet(mixed $index): mixed { return 'g' . parent::offsetGet($index); }
}

final class OverIter extends SplFixedArray
{
    public function getIterator(): Iterator { return new ArrayIterator(['x', 'y']); }
}

$a = new SplFixedArray(4);
$a[0] = 10; $a[1] = 'two'; $a[2] = null; $a[3] = new Box(4);
foreach ($a as $k => $v) {
    echo $k, '=', is_object($v) ? 'Box' . $v->n : var_export($v, true), "\n";
}

echo "-- empty\n";
foreach (new SplFixedArray(0) as $v) { echo "never\n"; }

echo "-- break/continue\n";
foreach ($a as $k => $v) {
    if ($k === 1) { continue; }
    if ($k === 3) { break; }
    echo $k, "\n";
}

echo "-- values only\n";
$p = new Plain(3);
foreach ([0, 1, 2] as $i) { $p[$i] = $i * $i; }
foreach ($p as $v) { echo $v, ' '; }
echo "\n";

echo "-- grow while iterating\n";
$g = new SplFixedArray(2);
$g[0] = 'a'; $g[1] = 'b';
foreach ($g as $k => $v) {
    echo $k, $v, ' ';
    if ($k === 0) { $g->setSize(4); $g[2] = 'c'; $g[3] = 'd'; }
}
echo "\n";

echo "-- shrink while iterating\n";
$s = new SplFixedArray(5);
foreach ([0, 1, 2, 3, 4] as $i) { $s[$i] = $i; }
foreach ($s as $k => $v) {
    echo $k, ' ';
    if ($k === 1) { $s->setSize(3); }
}
echo "\n";

echo "-- element rewritten while iterating\n";
$w = new SplFixedArray(3);
foreach ([0, 1, 2] as $i) { $w[$i] = $i; }
foreach ($w as $k => $v) {
    echo $v, ' ';
    if ($k === 0) { $w[1] = 'changed'; }
}
echo "\n";

echo "-- nested over one array\n";
$n = new SplFixedArray(2);
$n[0] = 'p'; $n[1] = 'q';
foreach ($n as $x) { foreach ($n as $y) { echo $x, $y, ' '; } }
echo "\n";

echo "-- offsetGet override is bypassed\n";
$o = new OverGet(2);
$o[0] = 1; $o[1] = 2;
foreach ($o as $k => $v) { echo $k, '=', $v, ' '; }
echo '| ', $o[0], "\n";

echo "-- getIterator override\n";
$i = new OverIter(3);
foreach ($i as $k => $v) { echo $k, '=', $v, ' '; }
echo "\n";

echo "-- value var survives the loop\n";
foreach ($p as $k => $last) {}
echo $k, ' ', $last, "\n";

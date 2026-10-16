<?php
use Manticore\Ds\{Int8Array, UInt16Array, Int32Array};

function fill(Int32Array $a, int $n): void
{
    for ($i = 0; $i < $n; $i++) { $a[$i] = (($a[$i] + $i) << 2 | 1) ^ ($i & 3); }
}
function narrow(Int8Array $a, int $i, int $x): void
{
    $a[$i] = $x & 0xff;
}
function mask(UInt16Array $a, int $n): void
{
    for ($i = 0; $i < $n; $i++) { $a[$i] = ~$i & 0xffff; }
}

$a = new Int32Array(5);
fill($a, 5);
echo implode(',', $a->toArray()), "\n";

$b = new Int8Array(2);
narrow($b, 0, 100);
try { narrow($b, 1, 200); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { narrow($b, 2, 1); } catch (OutOfBoundsException $e) { echo $e->getMessage(), "\n"; }
echo implode(',', $b->toArray()), "\n";

$c = new UInt16Array(3);
mask($c, 3);
echo implode(',', $c->toArray()), "\n";

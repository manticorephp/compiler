<?php
use Manticore\Ds\Int16Array;
use Manticore\Ds\Float64Array;
use Manticore\Ds\BitArray;

function second(\ArrayAccess $a): mixed { return $a[1]; }
function total(Manticore\Ds\TypedArray $a): float { $s = 0.0; foreach ($a as $v) { $s += (float)$v; } return $s; }
function viaBase(Manticore\Ds\TypedArray $a, int $i): mixed { return $a[$i]; }
function viaCount(\Countable $c): int { return count($c); }

$i = Int16Array::fromArray([5, -6, 7]);
$f = Float64Array::fromArray([0.5, 1.5]);
$b = BitArray::fromArray([false, true]);
$s = new SplFixedArray(2); $s[1] = 'spl';
var_dump(second($i), second($f), second($b), second($s));
var_dump(total($i), total($f), total($b));
var_dump(viaBase($i, 2), viaBase($f, 0), viaBase($b, 0));
echo viaCount($i), viaCount($f), viaCount($b), "\n";
try { viaBase($i, 3); } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }

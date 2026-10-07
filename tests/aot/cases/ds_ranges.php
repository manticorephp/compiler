<?php
use Manticore\Ds\Int8Array;
use Manticore\Ds\Int16Array;
use Manticore\Ds\Int32Array;
use Manticore\Ds\Int64Array;
use Manticore\Ds\UInt8Array;
use Manticore\Ds\UInt16Array;
use Manticore\Ds\UInt32Array;
use Manticore\Ds\Float32Array;
use Manticore\Ds\Float64Array;
use Manticore\Ds\BitArray;

function put(Manticore\Ds\TypedArray $a, mixed $v): void
{
    try { $a[0] = $v; var_dump($a[0]); }
    catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}

$ints = [
    [new Int8Array(1), -128, 127], [new Int16Array(1), -32768, 32767], [new Int32Array(1), -2147483648, 2147483647],
    [new UInt8Array(1), 0, 255], [new UInt16Array(1), 0, 65535], [new UInt32Array(1), 0, 4294967295],
];
foreach ($ints as $row) {
    $a = $row[0];
    echo get_class($a), "\n";
    put($a, $row[1]); put($a, $row[2]); put($a, $row[1] - 1); put($a, $row[2] + 1);
}
$q = new Int64Array(1);
put($q, PHP_INT_MIN); put($q, PHP_INT_MAX); put($q, 9.3e18); put($q, -9223372036854775808.0); put($q, 1e300);

$a = new Int32Array(1);
put($a, 1.5); put($a, 3.0); put($a, -0.0); put($a, '12'); put($a, ' 12'); put($a, '1e2'); put($a, '1.5'); put($a, 'abc');
put($a, '12abc'); put($a, ''); put($a, true); put($a, false); put($a, null); put($a, [1]); put($a, new stdClass());
put($a, NAN); put($a, INF); put($a, '4294967296');

$f = new Float32Array(1);
put($f, 0.1); put($f, 16777217); put($f, 1e39); put($f, -1e39); put($f, 1e-46); put($f, '2.5'); put($f, true); put($f, 'x'); put($f, null);
$d = new Float64Array(1);
put($d, 0.1); put($d, 7); put($d, PHP_INT_MAX); put($d, '1e3'); put($d, false); put($d, []);
var_dump(is_nan((function () use ($d) { $d[0] = NAN; return $d[0]; })()));
echo $d->indexOf(NAN), "\n";

$b = new BitArray(1);
put($b, true); put($b, 0); put($b, 2); put($b, 0.0); put($b, 0.5); put($b, '0'); put($b, ''); put($b, 'a'); put($b, '0.0');
put($b, null); put($b, []); put($b, new stdClass());

try { Int8Array::fromArray([1, 2, 300]); } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$z = new UInt8Array(3);
try { $z->fill(256); } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { $z->insert(0, 2, -1); } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo count($z), ' ', $z->indexOf(999), ' ', $z->indexOf(0), "\n";
try { $z->indexOf('nope'); } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }

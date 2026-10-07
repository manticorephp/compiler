<?php
// A UInt64Array element past 2^63 encodes as its unsigned value, exactly; the
// round trip back is JSON_BIGINT_AS_STRING + fromArray(). Superset: no oracle.
use Manticore\Ds\UInt64Array;

$h = new UInt64Array(4);
$h[0] = '18446744073709551615';
$h[1] = 5;
$h[2] = '9223372036854775808';
$h[3] = PHP_INT_MAX;
echo json_encode($h), "\n";
echo json_encode(['h' => $h, 'n' => null], JSON_PRETTY_PRINT), "\n";
var_dump($h->toArray()[0]);
$d = json_decode(json_encode($h), true, 512, JSON_BIGINT_AS_STRING);
var_dump($d);
$r = UInt64Array::fromArray($d);
var_dump($r->getString(0), $r->getString(2), $r[1], $r[3]);

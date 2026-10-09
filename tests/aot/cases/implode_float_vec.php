<?php
// implode over vec[float]: digit-only values written in place, the rest via the (string) formatter.
$f = [];
foreach ([0.0, 1.0, -1.0, 0.5, -0.5, 1.5, -2.5, 12345.5, 9999999999999.0, 9999999999999.5,
          10000000000000.0, 99999999999999.0, 1.0E+14, 1.0E+25, -1.0E+25, 0.1, 0.25, 0.30000000000000004,
          1.0E-7, 123456789.123456789, 3.14159265358979, 1.0E+100, NAN, INF, -INF, -0.0, 2.0, 7.5] as $x) {
    $f[] = $x;
}
echo implode(",", $f), "\n";
echo implode(" :: ", $f), "\n";
echo implode("", $f), "\n";

$one = [];
$one[] = 4.5;
echo implode(",", $one), "|", implode(",", [1.5]), "\n";

$none = [];
$none[] = 1.5;
array_pop($none);
echo "[", implode(",", $none), "]\n";

$g = [];
for ($i = 0; $i < 500; $i++) {
    $g[] = ($i % 7 === 0) ? $i / 3 : $i + 0.5;
}
$s = implode(";", $g);
echo strlen($s), " ", md5($s), "\n";

$k = ["a" => 1.5, "b" => 2.0, "c" => 0.1];
echo implode("-", $k), "\n";

$mixed = [1, 2.5, "x", true, null, 3.0];
echo implode(",", $mixed), "\n";

$acc = 0;
$h = [];
for ($i = 0; $i < 2000; $i++) { $h[] = $i + 0.5; }
for ($r = 0; $r < 200; $r++) { $acc += strlen(implode(",", $h)); }
echo $acc, "\n";

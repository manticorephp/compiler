<?php
$N = 10000;
$m = [];
for ($i = 0; $i < $N; $i++) { $m["key_$i"] = $i; }
for ($i = 0; $i < $N; $i += 3) { unset($m["key_$i"]); }
$bad = 0;
for ($i = 0; $i < $N; $i++) { if (isset($m["key_$i"]) !== ($i % 3 !== 0)) { $bad++; } }
echo "after unset bad=$bad count=", count($m), "\n";
for ($i = 0; $i < $N; $i += 3) { $m["key_$i"] = -$i; }
$sum = 0;
for ($i = 0; $i < $N; $i++) { $sum += $m["key_$i"]; }
echo "reinsert sum=$sum count=", count($m), "\n";
$c = $m;
for ($i = 0; $i < $N; $i += 2) { unset($c["key_$i"]); }
$bad = 0;
for ($i = 0; $i < $N; $i++) {
    if (!isset($m["key_$i"])) { $bad++; }
    if (isset($c["key_$i"]) !== ($i % 2 === 1)) { $bad++; }
}
echo "cow bad=$bad m=", count($m), " c=", count($c), "\n";
$c["extra"] = 1;
echo isset($m["extra"]) ? "leak" : "ok", " ", $c["extra"], "\n";
$im = [];
for ($i = 0; $i < $N; $i++) { $im[$i * 4099 + 7] = $i; }
for ($i = 0; $i < $N; $i += 3) { unset($im[$i * 4099 + 7]); }
$bad = 0;
for ($i = 0; $i < $N; $i++) { if (isset($im[$i * 4099 + 7]) !== ($i % 3 !== 0)) { $bad++; } }
for ($i = 0; $i < $N; $i += 3) { $im[$i * 4099 + 7] = $i * 2; }
$sum = 0;
foreach ($im as $k => $v) { $sum += $v; }
echo "int bad=$bad sum=$sum count=", count($im), "\n";
$d = $im; unset($d[7]); unset($d[4099 * 5 + 7]);
echo isset($im[7]) ? 1 : 0, isset($d[7]) ? 1 : 0, isset($d[4099 * 5 + 7]) ? 1 : 0, isset($d[4099 * 6 + 7]) ? 1 : 0, "\n";
// same-length long keys with a shared prefix, one differing byte
$p = [];
for ($i = 0; $i < 3000; $i++) { $p[str_repeat('x', 30) . $i] = $i; }
$bad = 0;
for ($i = 0; $i < 3000; $i++) { if ($p[str_repeat('x', 30) . $i] !== $i) { $bad++; } }
for ($i = 0; $i < 3000; $i++) { if (isset($p[str_repeat('y', 30) . $i])) { $bad++; } }
echo "prefix bad=$bad\n";
// mixed pop/shift and re-lookup
$q = [];
for ($i = 0; $i < 2000; $i++) { $q["k$i"] = $i; }
array_pop($q); array_shift($q);
$bad = 0;
for ($i = 1; $i < 1999; $i++) { if ($q["k$i"] !== $i) { $bad++; } }
echo "popshift bad=$bad count=", count($q), "\n";

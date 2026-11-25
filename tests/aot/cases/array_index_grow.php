<?php
// The bucket index of a hashed array doubles in place as entries are appended
// past its load factor, with lookups in between keeping it alive; every key
// must stay findable through growth, unsets and re-inserts.
$m = [];
$miss = 0;
for ($i = 0; $i < 5000; $i++) {
    $m["k" . $i] = $i;
    if (!isset($m["k" . intdiv($i, 2)])) { $miss++; }
    if (isset($m["x" . $i])) { $miss++; }
}
for ($i = 0; $i < 5000; $i += 3) { unset($m["k" . $i]); }
for ($i = 0; $i < 5000; $i += 6) { $m["k" . $i] = -$i; }
$sum = 0;
$found = 0;
for ($i = 0; $i < 5000; $i++) {
    $k = "k" . $i;
    if (array_key_exists($k, $m)) { $found++; $sum += $m[$k]; }
}
echo count($m), " ", $found, " ", $sum, " ", $miss, "\n";

$h = [];
for ($i = 0; $i < 3000; $i++) {
    $h[$i * 4096] = $i;
    $h[-$i - 1] = $i;
    if (($h[intdiv($i, 3) * 4096] ?? -1) !== intdiv($i, 3)) { $miss++; }
}
$ok = 0;
for ($i = 0; $i < 3000; $i++) {
    if ($h[$i * 4096] === $i && $h[-$i - 1] === $i) { $ok++; }
}
echo count($h), " ", $ok, " ", $miss, "\n";

$c = ["a" => 1];
for ($i = 0; $i < 40; $i++) { $c["p" . $i] = $i; $d = $c; $d["q" . $i] = $i; }
echo count($c), " ", count($d), " ", $d["q39"], " ", isset($c["q39"]) ? "leak" : "ok", "\n";

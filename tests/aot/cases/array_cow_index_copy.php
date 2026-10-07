<?php
function build(): array {
    $m = [];
    for ($i = 0; $i < 100; $i++) { $m[$i * 37 + 5] = $i; }
    return $m;
}
function buildS(): array {
    $m = [];
    for ($i = 0; $i < 100; $i++) { $m["k" . $i] = $i; }
    return $m;
}
function dump(string $tag, array $m, array $keys): void {
    $s = 0; $miss = 0;
    foreach ($keys as $k) { if (isset($m[$k])) { $s += $m[$k]; } else { $miss++; } }
    echo $tag, " ", count($m), " ", $s, " ", $miss, "\n";
}
$ik = []; $sk = [];
for ($i = 0; $i < 130; $i++) { $ik[] = $i * 37 + 5; $sk[] = "k" . $i; }
$a = build(); $b = $a;
dump("i0a", $a, $ik); dump("i0b", $b, $ik);
for ($i = 0; $i < 100; $i += 3) { unset($b[$i * 37 + 5]); }
for ($i = 100; $i < 120; $i++) { $b[$i * 37 + 5] = $i; }
$a[5] = 1000; $a[42] = 7;
dump("i1a", $a, $ik); dump("i1b", $b, $ik);
$c = $b; unset($c[42 + 0]); $c[1000000] = 1; unset($a[5]);
dump("i2a", $a, $ik); dump("i2b", $b, $ik); dump("i2c", $c, $ik);
$a = buildS(); $b = $a;
dump("s0a", $a, $sk); dump("s0b", $b, $sk);
for ($i = 0; $i < 100; $i += 3) { unset($b["k" . $i]); }
for ($i = 100; $i < 120; $i++) { $b["k" . $i] = $i; }
$a["k5"] = 1000; $a["zz"] = 3;
dump("s1a", $a, $sk); dump("s1b", $b, $sk);
$c = $a; unset($c["k7"]); $c["k200"] = 9; unset($a["zz"]);
dump("s2a", $a, $sk); dump("s2c", $c, $sk);
echo isset($c["zz"]) ? "y" : "n", isset($a["zz"]) ? "y" : "n", "\n";

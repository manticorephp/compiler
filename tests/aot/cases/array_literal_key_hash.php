<?php
// Precomputed FNV hash for literal keys must match the runtime hash on a
// LARGE (>=16 entry) hashed map, incl. set, get, and isset.
$a = [];
$keys = ["alpha","beta","gamma","delta","epsilon","zeta","eta","theta",
         "iota","kappa","lambda","mu","nu","xi","omicron","pi","rho","sigma"];
$v = 1;
foreach ($keys as $k) { $a[$k] = $v; $v++; }
echo $a["alpha"], ",", $a["sigma"], ",", $a["omicron"], "\n";  // 1,18,15
$a["alpha"] = 100;          // literal-key update on a large map
echo $a["alpha"], "\n";    // 100
echo isset($a["lambda"]) ? "y" : "n", isset($a["zzz"]) ? "y" : "n", "\n"; // yn
$sum = 0;
foreach ($a as $kk => $vv) { $sum += $vv; }
echo $sum, "\n";           // 1..18 sum, with alpha=100 → 170+100-1=... 1+..+18=171, -1+100=270

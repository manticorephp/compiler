<?php
// String hash cache in the header. Correctness must hold across: repeated
// lookups (cache hit), long keys, a key mutated in place after being hashed
// (invalidation), and COW-on-append of a retained key.
$m = [];
for ($i = 0; $i < 500; $i++) { $m["user_key_" . $i] = $i * 3; }
$sum = 0;
for ($r = 0; $r < 3; $r++) {
    for ($i = 0; $i < 500; $i++) { $sum += $m["user_key_" . $i]; }
}
echo $sum, "\n";                              // 3 * sum(0..499)*3 = 1123500

// hashed (isset, non-retaining) then in-place append -> hash must invalidate
$u = "zz";
$a = isset($m[$u]) ? 1 : 0;                   // hashes "zz"
$u .= "top";                                  // in-place if sole-owned
$b = isset($m[$u]) ? 1 : 0;                   // must re-hash "zztop"
echo $a, $b, "\n";                            // 00

// retained key then append -> COW, both keys resolve
$k = "aa";
$t = [];
$t[$k] = 10;
$k .= "bb";
$t[$k] = 20;
echo $t["aa"], " ", $t["aabb"], " ", count($t), "\n";   // 10 20 2

// empty-string key (baked FNV of "")
$e = ["" => 7];
echo $e[""], "\n";                            // 7

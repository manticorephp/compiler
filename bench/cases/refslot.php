<?php
// refslot — real &$a[$k] reference on a large hashed map: the ref-slot path
// that today linear-scans the entry array and ignores the bucket index.
// (Plain $m[$k]++ lowers to indexed get+set, which is why this case takes
// an explicit reference.)
$m = [];
$keys = [];
for ($i = 0; $i < 2000; $i++) { $m["k" . $i] = 0; $keys[] = "k" . $i; }
$reps = 50 * $argc;
for ($r = 0; $r < $reps; $r++) {
    foreach ($keys as $k) {
        $ref = &$m[$k];
        $ref = $ref + 1;
        unset($ref);
    }
}
$sum = 0;
foreach ($m as $v) { $sum += $v; }
echo $sum, "\n";

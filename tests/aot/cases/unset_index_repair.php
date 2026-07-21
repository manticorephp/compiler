<?php
// Index-repair torture: a hashed map big enough to carry a bucket index,
// interleaved unset / lookup / isset / re-insert. After every unset, EVERY
// remaining key must still be found (covers same-cluster backshift and
// wraparound regardless of hash layout), and count() must track.
$m = [];
for ($i = 0; $i < 48; $i++) { $m["key" . $i] = $i * 10; }
foreach ($m as $k => $v) { /* touch */ }
$probe = 0;
for ($i = 0; $i < 48; $i++) { $probe += $m["key" . $i]; }
echo "probe=", $probe, " n=", count($m), "\n";

for ($del = 0; $del < 48; $del += 3) {
    unset($m["key" . $del]);
    $sum = 0;
    $miss = 0;
    for ($i = 0; $i < 48; $i++) {
        if (isset($m["key" . $i])) { $sum += $m["key" . $i]; } else { $miss++; }
    }
    echo $del, ":", $sum, ":", $miss, ":", count($m), "\n";
}

for ($del = 0; $del < 48; $del += 3) { $m["key" . $del] = $del * 100; }
$sum = 0;
for ($i = 0; $i < 48; $i++) { $sum += $m["key" . $i]; }
echo "reinsert=", $sum, " n=", count($m), "\n";

$keys = "";
$j = 0;
foreach ($m as $k => $v) { if ($j % 11 === 0) { $keys .= $k . ","; } $j++; }
echo "order=", $keys, "\n";

<?php
// foreach + count over a large assoc, repeated — exercises __mir_array_live_len
// (the array-length choke point, now tag-guarded) once per loop / count call.
$n = 200000;
$a = [];
for ($i = 0; $i < $n; $i++) { $a["k" . $i] = $i; }
$sum = 0;
// The read passes scale (* $argc), the map does not — a constant working set,
// so RSS growth under LEAK=1 is a leak and nothing else.
$reps = 10 * $argc;
for ($r = 0; $r < $reps; $r++) {
    foreach ($a as $k => $v) { $sum = ($sum + $v) % 1000000007; }
    $sum = ($sum + count($a)) % 1000000007;
}
echo $sum, "\n";

<?php
// A loop-carried accumulator that starts int and is compound-assigned a float
// must become a FLOAT slot — the back-edge merge would otherwise erase it
// (int ∪ float → unknown) and read the float bits as a garbage integer.
$s = 0;
for ($i = 0; $i < 100; $i++) { $s += 1.5; }
echo $s, "\n";

$t = 5;                          // non-zero int init must coerce (sitofp), not bit-store
for ($i = 0; $i < 10; $i++) { $t += 0.25; }
printf("%.2f\n", $t);

$u = 0.0;
for ($i = 1; $i < 10; $i++) { $u += sqrt((float)$i); }
printf("%.4f\n", $u);

$n = 0;                          // pure-int accumulator stays int (not promoted)
for ($i = 0; $i < 5; $i++) { $n += 2; }
var_dump($n);
var_dump($s);

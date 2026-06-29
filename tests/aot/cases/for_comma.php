<?php
for ($i=0, $s=0; $i < 5; $i++, $s += $i) { echo $i, ":", $s, " "; }
echo "\n";
$r = 0;
for ($i=1, $j=1; $i <= 5; $i++, $j *= $i) { $r += $j; }
echo $r, "\n";
for ($i=0; $i<3; $i++) echo $i;
echo "\n";

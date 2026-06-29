<?php
$xs = [0];
for ($i = 1; $i < 5; $i++) { $xs[] = $i * $i; }
$total = 0;
foreach ($xs as $v) { $total += $v; }
echo $total;

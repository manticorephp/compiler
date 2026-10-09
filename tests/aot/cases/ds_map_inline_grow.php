<?php
use Manticore\Ds\Map;
$m = new Map(); $sum = 0;
for ($i = 0; $i < 20000; $i++) {
    $m->set($i, $i);
    $sum += $m->get(intdiv($i, 2), 0);
    if ($i % 3 === 0) { $m->remove(intdiv($i, 3)); }
    if ($m->has(intdiv($i, 3))) { $sum++; }
}
echo $sum, ' ', count($m), "\n";

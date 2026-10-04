<?php
// @serial: a memory measurement.
$cfg = null;
function setg(int $i): int { global $cfg; $cfg = ['name' => 'n' . $i, 'pad' => str_repeat('g', 256)]; return count($cfg); }
function stat_local(int $i): int { static $last = null; $last = 'v' . $i . str_repeat('h', 256); return strlen($last); }
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += setg($i) + stat_local($i); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { $sum += setg($i) + stat_local($i); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

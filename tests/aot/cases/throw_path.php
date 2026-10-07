<?php
// LEAK: every thrown-and-caught exception leaks
// issue: #25
function thr(int $i): void { throw new \RuntimeException(str_repeat('e', 200) . $i); }
$m0 = memory_get_peak_usage();
$k = 0;
for ($i = 0; $i < 100000; $i++) { try { thr($i); } catch (\RuntimeException $e) { $k++; } }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";

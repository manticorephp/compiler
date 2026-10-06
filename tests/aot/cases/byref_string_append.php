<?php
// LEAK: `function f(string &$s) { $s .= 'x'; }` leaks every old buffer (quadratic)
// issue: #21
function f(string &$s): void { $s .= 'x'; }
$m0 = memory_get_peak_usage();
$s = '';
for ($i = 0; $i < 6000; $i++) { f($s); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";

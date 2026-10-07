<?php
// f(string &$s){ $s .= 'x'; } leaked every old buffer: 1.08 GB after 10k calls.
// @serial: a memory measurement.
function f(string &$s): void { $s .= 'x'; }
$s = '';
$b = memory_get_usage();
$t = microtime(true);
for ($i = 0; $i < 20000; $i++) { f($s); }
$g = memory_get_usage() - $b;
echo strlen($s), ' ', $g < 4 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

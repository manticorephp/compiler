<?php
// @serial: a memory measurement.
// An int cell handed to `string &$x` is rendered on entry ("3"); the rendered
// string becomes the slot's own after the re-box. Neither the rendering nor
// the callee's overwrite of it may leak or release twice.
function app(string &$x): void { $x .= 'a'; }
function peek(string &$x): int { return strlen($x); }
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $m = [$i, 'z']; app($m[0]); $n = [$i]; $sum += peek($n[0]); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { $m = [$i, 'z']; app($m[0]); app($m[1]); $n = [$i]; $sum += peek($n[0]); }
$g = memory_get_usage() - $b;
var_dump($m, $n);
echo $sum, ' ', $g < 2 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

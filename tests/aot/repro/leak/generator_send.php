<?php
// LEAK: a fresh value passed to Generator::send() is never released
// issue: #22
function g(): \Generator { while (true) { $v = yield; } }
function mk(int $i): string { return str_repeat('s', 300) . $i; }
$m0 = memory_get_peak_usage();
$g = g();
$g->current();
for ($i = 0; $i < 100000; $i++) { $g->send(mk($i)); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";

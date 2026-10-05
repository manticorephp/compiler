<?php
// LEAK: every heap value a generator yields into a foreach is never released
// issue: #23
final class M { public function __construct(public readonly string $data) {} }
function gObj(int $n): \Generator { for ($i = 0; $i < $n; $i++) { yield new M(str_repeat('x', 100) . $i); } }
function gStr(int $n): \Generator { for ($i = 0; $i < $n; $i++) { yield str_repeat('x', 100) . $i; } }
$m0 = memory_get_peak_usage();
foreach (gObj(100000) as $v) { $d = $v->data; }
foreach (gStr(100000) as $v) { }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "LEAK\n";

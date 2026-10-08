<?php
// A temporary argument (array literal, closure literal) of a call that throws is never released.
// issue: #145
function t(array $a): bool { throw new RuntimeException('x'); }
function run(int $i): int
{
    try { t(['a' . $i, str_repeat('b', 200)]); } catch (RuntimeException $e) { return 1; }
    return 0;
}
for ($i = 0; $i < 200; $i++) { run($i); }
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { run($i); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 2 ? "flat\n" : "LEAK\n";

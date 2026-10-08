<?php
// A throw inside an assignment's right-hand side that also reads the target (`$c = $t ? throw … : $c . $v`) leaks the target's old value.
// issue: #144
function acc(array $a): string
{
    $c = '';
    foreach ($a as $k => $v) { $c = $k === 1 ? throw new RuntimeException('z') : $c . $v; }
    return $c;
}
function run(int $i): int
{
    try { acc([str_repeat('v', 200) . $i, 'b']); } catch (RuntimeException $e) { return 1; }
    return 0;
}
for ($i = 0; $i < 200; $i++) { run($i); }
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { run($i); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 2 ? "flat\n" : "LEAK\n";

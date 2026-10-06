<?php
// One loop variable bound by two iterator loops of different element classes:
// the second loop kept one reference per iteration. @serial: a memory measurement.
final class M { public function __construct(public readonly string $data) {} }
function gObj(int $n): \Generator { for ($i = 0; $i < $n; $i++) { yield new M(str_repeat('x', 300) . $i); } }
function gStr(int $n): \Generator { for ($i = 0; $i < $n; $i++) { yield str_repeat('y', 300) . $i; } }
function both(int $n): int
{
    $t = 0;
    foreach (gObj($n) as $v) { $t += strlen($v->data); }
    foreach (gStr($n) as $v) { $t += strlen($v); }
    return $t;
}
$t = both(2000);
$b = memory_get_usage();
$t += both(40000);
$d = memory_get_usage() - $b;
echo $t > 0 ? 'ran' : 'idle', "\n";
echo $d < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($d / 1048576, 1) . 'MB', "\n";

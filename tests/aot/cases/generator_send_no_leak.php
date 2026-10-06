<?php
// $g->send(mk($i)) leaked ~16 MB / 40k. @serial: a memory measurement.
final class Big { public string $s; public function __construct(int $i) { $this->s = str_repeat('q', 512) . $i; } }
function mk(int $i): Big { return new Big($i); }
function sink(): \Generator { $n = 0; while (true) { $v = yield $n; $n += strlen($v->s); } }
$g = sink(); $g->current();
for ($i = 0; $i < 2000; $i++) { $g->send(mk($i)); }
$b = memory_get_usage();
for ($i = 0; $i < 40000; $i++) { $g->send(mk($i)); }
$d = memory_get_usage() - $b;
echo $d < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($d / 1048576, 1) . 'MB', "\n";

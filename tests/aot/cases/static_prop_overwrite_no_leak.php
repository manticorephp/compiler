<?php
// @serial: a memory measurement.
final class Big { public string $s; public function __construct(int $i) { $this->s = str_repeat('s', 512) . $i; } }
final class Reg { public static ?Big $cur = null; /** @var array<int,string> */ public static array $bag = []; }
function tick(int $i): int { Reg::$cur = new Big($i); Reg::$bag = ['k' . $i, 'v' . $i]; $c = Reg::$cur; return strlen($c->s) + count(Reg::$bag); }
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += tick($i); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { $sum += tick($i); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

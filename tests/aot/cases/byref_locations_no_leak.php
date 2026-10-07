<?php
// @serial: a memory measurement.
final class Big { public string $s; public function __construct(int $i) { $this->s = str_repeat('r', 512) . $i; } }
final class H { public ?Big $p = null; public static ?Big $sp = null; }
function put(?Big &$slot, int $i): void { $slot = new Big($i); }
function many(int $i, &...$slots): void { foreach ($slots as &$s) { $s = 'v' . $i . str_repeat('m', 128); } }
$h = new H(); $a = [null]; $sum = 0;
for ($i = 0; $i < 2000; $i++) { put($h->p, $i); put(H::$sp, $i); put($a[0], $i); $x = ''; $y = ''; many($i, $x, $y); $sum += strlen($x); }
$b = memory_get_usage();
for ($i = 0; $i < 100000; $i++) { put($h->p, $i); put(H::$sp, $i); put($a[0], $i); $x = ''; $y = ''; many($i, $x, $y); $sum += strlen($x); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

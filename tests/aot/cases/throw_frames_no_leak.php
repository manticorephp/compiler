<?php
// ~3.1 MB per 40k throws on main: owned locals of unwound frames were never released.
// @serial: a memory measurement.
final class Big { public string $s; public function __construct(int $i) { $this->s = str_repeat('t', 512) . $i; } }
function c3(int $i): int { $x = new Big($i); if ($i >= 0) { throw new \RuntimeException('x'); } return strlen($x->s); }
function c2(int $i): int { $y = new Big($i); $z = 'k' . $i . str_repeat('u', 64); return c3($i) + strlen($y->s) + strlen($z); }
function c1(int $i): int { $w = [new Big($i)]; return c2($i) + count($w); }
$n = 0;
for ($i = 0; $i < 2000; $i++) { try { c1($i); } catch (\RuntimeException $e) { $n++; } }
$b = memory_get_usage();
for ($i = 0; $i < 40000; $i++) { try { c1($i); } catch (\RuntimeException $e) { $n++; } }
$g = memory_get_usage() - $b;
echo $n, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

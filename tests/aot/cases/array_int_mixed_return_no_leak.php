<?php
// An `array<int, mixed>` a call returns: the buffer and its heap elements are released
// when the local is overwritten. @serial: a memory measurement.
final class Big { public string $pool; public function __construct(int $i) { $this->pool = str_repeat('x', 512) . $i; } }
/** @return array<int, mixed> */
function mk(int $i): array { return [new Big($i), 'k' . $i, $i]; }
function run(int $n): int { $t = 0; for ($i = 0; $i < $n; $i++) { $a = mk($i); $t += count($a); } return $t; }
run(2000); $b = memory_get_usage(); run(40000); $d = memory_get_usage() - $b;
echo $d < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($d / 1048576, 1) . 'MB', "\n";

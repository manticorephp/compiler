<?php
// A by-value param starts borrowed; storing it, overwriting it with an owned value,
// returning either — no leak, no double free. @serial: a memory measurement.
final class Big { public string $s; public function __construct(int $i) { $this->s = str_repeat('z', 512) . $i; } }
/** @return int[] */
function mk(int $n): array { $r = []; for ($j = 0; $j < $n; $j++) { $r[] = $j; } return $r; }
function f(Big $p, int $i): Big { if ($i % 3 === 0) { $p = new Big($i); } $q = $p; return $i % 2 === 0 ? $q : $p; }
function g(string $s, int $i): string { $t = $s; if ($i % 2 === 0) { $t = $s . $i; } return $t; }
/** @param int[] $a @return int[] */
function h(array $a, int $i): array { if ($i % 3 === 0) { $a = mk(60 + $i % 5); } $q = $a; return $i % 2 === 0 ? $q : $a; }
$o = new Big(0); $l = [1, 2, 3]; $sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += strlen(f($o, $i)->s) + strlen(g('ab', $i)) + count(h($l, $i)); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { $sum += strlen(f($o, $i)->s) + strlen(g('ab', $i)) + count(h($l, $i)); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

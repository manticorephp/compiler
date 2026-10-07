<?php
function a(mixed ...$v): float { return (float)($v[0] ?? 0.0); }
/** @param array<int, float> $l */
function b(array $l, int $i): float { return ($l[$i] ?? -1.5) * 2; }
/** @param array<string, float> $m */
function c(array $m, string $k): string { $x = $m[$k] ?? 0.25; return number_format($x, 3); }
function d(?float $f): float { return ($f ?? 9.5) + 0.5; }
/** @param array<int, float> $l */
function e(array $l): int { return (int)($l[0] ?? 7.9); }
var_dump(a(0.1), a(-2.5), a(3), a(), a('4.5'));
var_dump(b([0.5, 1.25], 1), b([0.5], 4), c(['k' => 1.5], 'k'), c([], 'z'), d(null), d(1.0), e([2.7]), e([]));
$w = [0.1, 0.2];
$s = 0.0;
for ($i = 0; $i < 4; $i++) { $s += $w[$i] ?? 1.0; }
var_dump($s, max($w[5] ?? 0.75, 0.5), round(($w[1] ?? 0.0) * 10));

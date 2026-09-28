<?php
// A closure called with fewer arguments than it declares takes its defaults,
// whoever calls it: the closure ABI carries no arity, and a prelude body
// (array_map, usort) could not pad for a closure it cannot name.
$fs = [fn ($a, $b = '!') => $a . $b, fn ($a, $b = '?', $c = '#') => $a . $b . $c, fn ($a) => $a . '.'];
foreach ($fs as $f) { echo json_encode(array_map($f, ['x', 'y'])), "\n"; }
$cmp = [fn ($a, $b, $d = 1) => $d * ($a <=> $b), fn ($a, $b, $d = -1) => $d * ($a <=> $b)];
foreach ($cmp as $c) { $xs = [2, 3, 1]; usort($xs, $c); echo json_encode($xs), "\n"; }
function g(string $s, string $m = '?'): string { return $s . $m; }
function viaCallable(callable $c, array $xs): array { return array_map($c, $xs); }
$f = fn ($a, $b = '!', int $n = 2) => $a . str_repeat($b, $n);
echo json_encode(array_map($f, ['x', 'y'])), "\n";
echo json_encode(viaCallable($f, ['p'])), "\n";
echo json_encode(viaCallable('g', ['q'])), "\n";
echo json_encode(viaCallable('trim', ['  t  ', 'null'])), "\n";
echo json_encode(array_map('trim', explode('|', 'string|bool|int|float|null'))), "\n";
$v = function ($a, ...$rest) { return $a . '/' . count($rest); };
echo json_encode(array_map($v, [1, 2])), "\n";
$r = function ($a, &$acc = null) { $acc = $a; return $a * 2; };
echo json_encode(array_map($r, [3])), "\n";
$cmp = fn ($a, $b, $desc = false) => $desc ? $b <=> $a : $a <=> $b;
$xs = [3, 1, 2]; usort($xs, $cmp); echo json_encode($xs), "\n";
echo $f('z'), ' ', $f('z', '-'), ' ', $f('z', '+', 3), "\n";
$h = $f; echo $h('w'), "\n";

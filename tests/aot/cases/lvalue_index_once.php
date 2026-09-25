<?php

// A write target's dims and receiver are evaluated ONCE, left to right,
// before the right-hand side: a nested store, an unset, a compound assign, an
// increment, an append and a property chain on a call receiver. Each line
// prints how many times the side effect ran, then the result.
$calls = 0;
function k(): string { global $calls; $calls++; return 'r1'; }
function f(): int { global $calls; $calls++; return 0; }
final class B { public array $arr = ['a']; public int $n = 0; }
$bb = new B();
function mk(): B { global $calls, $bb; $calls++; return $bb; }
$i = 0; $rows = [['n' => 0], ['n' => 0]];
$rows[$i++]['n'] = 7;
echo $i, json_encode($rows), "\n";
$calls = 0; $r2 = ['r1' => ['n' => 1, 'm' => 2]]; unset($r2[k()]['n']); echo $calls, json_encode($r2), "\n";
$i = 0; $n = [[['a' => 0]], [['a' => 0]]]; $n[$i++][0]['a'] = 9; echo $i, json_encode($n), "\n";
$calls = 0; $a = [['x' => 'p', 'c' => 1]]; $a[f()]['x'] .= 'y'; echo $calls, json_encode($a), "\n";
$calls = 0; $a[f()]['c']++; echo $calls, json_encode($a), "\n";
$calls = 0; $a[f()][] = 1; echo $calls, json_encode($a), "\n";
$calls = 0; $a[f()]['c'] += 5; echo $calls, json_encode($a), "\n";
$calls = 0; mk()->n++; echo $calls, ' ', $bb->n, "\n";
$calls = 0; mk()->arr[0] .= 'z'; echo $calls, ' ', json_encode($bb->arr), "\n";
$calls = 0; mk()->arr[f()] = 'q'; echo $calls, ' ', json_encode($bb->arr), "\n";
function inner(): void { global $calls; $i = 0; $m = [[1], [2]]; $m[$i++][] = 3; $m[$i++][0] *= 10; echo $i, json_encode($m), "\n"; $s = ['k' => ['x' => 1]]; $s[strtolower('K')]['x'] ??= 5; $s[strtolower('K')]['y'] ??= 6; echo json_encode($s), "\n"; }
inner();

// A dim or receiver holding a closure whose own statements hoist too, and a
// generator body doing the same.
$trace = [];
function tf(string $s): int { global $trace; $trace[] = $s; return 0; }
$kk = [[0]];
$kk[tf('a')][(function () { $in = [[0]]; $in[tf('i')][0] = 1; return 1; })()] = 2;
echo implode(',', $trace), json_encode($kk), "\n";
final class Holder2 { public int $p = 0; }
$trace = [];
$hold = new Holder2();
(function () use ($hold) { $x = [[0]]; $x[tf('r')][0] = 3; return $hold; })()->p = 4;
echo implode(',', $trace), ' ', $hold->p, "\n";
function genw(): \Generator { $g = [[0], [0]]; $j = 0; $v = yield 1; $g[$j++][0] = $v; yield 2; $g[$j++][0] += 5; yield json_encode($g) . $j; }
$gen = genw();
$gen->current();
$gen->send(7);
$gen->next();
echo $gen->current(), "\n";

// A for-loop step (and init) is a statement: its dims run once per iteration.
$trace = [];
$z = [[0]];
for ($q = 0, $z[tf('init')][0] = 1; $q < 3; $q++, $z[tf('s')][0]++) { }
echo count($trace), json_encode($z), "\n";

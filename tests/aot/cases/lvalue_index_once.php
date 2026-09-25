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

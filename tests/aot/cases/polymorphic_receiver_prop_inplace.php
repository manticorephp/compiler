<?php
// By-ref array builtins on a property of a POLYMORPHIC local receiver (`K|H`):
// the receiver has no static slot, so the argument is not addressable and the
// callee's write must go back through the class_id property writer.
class H { public array $j = []; /** @var array<string,int> */ public array $m = []; }
class K extends H {}
$p = count($argv) > 0 ? new K : new H;
function show(string $l, array $a): void { echo $l, ': ', json_encode($a), "\n"; }
$p->j = [9, 8, 7]; sort($p->j); show('sort', $p->j);
$p->j = [7, 9, 8]; rsort($p->j); show('rsort', $p->j);
$p->j = [9, 8, 7]; usort($p->j, fn($a, $b) => $a <=> $b); show('usort', $p->j);
$p->j = [9, 8, 7]; array_push($p->j, 5, 6); show('push', $p->j);
$p->j = [9, 8, 7]; array_unshift($p->j, 4); show('unshift', $p->j);
$p->j = [9, 8, 7]; array_shift($p->j); show('shift', $p->j);
$p->j = [9, 8, 7]; array_pop($p->j); show('pop', $p->j);
$p->j = [9, 8, 7, 6]; array_splice($p->j, 1, 2, [0]); show('splice', $p->j);
$p->j = [9, 8, 7]; $r = shuffle($p->j); sort($p->j); show('shuffle', $p->j);
$p->m = ['b' => 2, 'a' => 3, 'c' => 1]; ksort($p->m); show('ksort', $p->m);
$p->m = ['b' => 2, 'a' => 3, 'c' => 1]; asort($p->m); show('asort', $p->m);
$p->m = ['b' => 2, 'a' => 3, 'c' => 1]; arsort($p->m); show('arsort', $p->m);
$p->m = ['b' => 2, 'a' => 3, 'c' => 1]; krsort($p->m); show('krsort', $p->m);
$p->m = ['b' => 2, 'a' => 3, 'c' => 1]; uasort($p->m, fn($a, $b) => $b <=> $a); show('uasort', $p->m);
$p->m = ['b' => 2, 'a' => 3, 'c' => 1]; uksort($p->m, fn($a, $b) => strcmp($b, $a)); show('uksort', $p->m);

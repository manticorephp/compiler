<?php
function t($l, $f) { echo $l, ': '; try { var_dump($f()); } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; } }
function fl(int $a, int $b): float { return $a * $b; }
function fa(int $a): float { return $a + 1; }
function takes(float $x) { return $x; }
function inc(int $a) { $a++; return $a; }
function dec(int $a) { $a--; return $a; }
function neg(int $a) { return -$a; }
function addi(int $a, int $b) { return $a + $b; }
function subi(int $a, int $b) { return $a - $b; }
function muli(int $a, int $b) { return $a * $b; }
function compound(int $a) { $a += PHP_INT_MAX; $a += 1; return $a; }
function compmul(int $a) { $a *= 4611686018427387904; return $a; }
function fa2(int $a, int $b) { return (float)($a + $b); }
function fa3(int $a, int $b) { return ($a * $b) / 2.0; }
function fa4(int $a, int $b) { return takes($a * $b); }
function fa5(int $a, int $b) { $s = 1.5; $s = $a + $b; return $s; }
function fa6(int $a, int $b): float { $x = $a + $b; return $x; }
function pabs(int $a) { return abs($a); }
$M = PHP_INT_MAX; $m = PHP_INT_MIN;
t('add', fn() => addi($M, 1)); t('add ok', fn() => addi($M, 0)); t('sub', fn() => subi($m, 1)); t('mul', fn() => muli($M, 2)); t('mul ok', fn() => muli(3037000499, 3037000499));
t('mul min -1', fn() => muli($m, -1)); t('neg', fn() => neg($m)); t('neg ok', fn() => neg(5));
t('inc', fn() => inc($M)); t('dec', fn() => dec($m)); t('inc ok', fn() => inc(5));
t('compound', fn() => compound(0)); t('compmul', fn() => compmul(2));
t('float return mul', fn() => fl($M, 2)); t('float return add', fn() => fa($M)); t('float ret ok', fn() => fl(3, 4));
t('cast', fn() => fa2($M, 1)); t('float arith', fn() => fa3($M, 2)); t('arg', fn() => fa4($M, 2)); t('local', fn() => fa5($M, 1));
t('abs min', fn() => pabs($m)); t('abs', fn() => pabs(-7));
t('mod', fn() => $m % -1); t('intdiv', fn() => intdiv($m, -1));
t('big ok', fn() => addi(4611686018427387904, 4611686018427387903));

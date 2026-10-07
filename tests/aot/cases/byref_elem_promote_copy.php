<?php
// @serial: a memory measurement.
// A local whose element a reference promotes is a cell-element array; bound to
// a COPY of a typed array (a param, another local) the store rebuilds the copy
// with boxed elements. It used to re-label the source as holding cells, so the
// element read back as the reference box's address and the source param was
// read as cells over a buffer of raw ints.
/** @param int[] $p */
function viaRefAddr(array $p): void { $a = $p; $r = &$a[0]; $r = 's'; unset($r); var_dump($a, $p); }
/** @param int[] $p */
function viaRefCell(array $p): void { $a = $p; $l = [&$a[0]]; $l[0] = 's'; unset($l); var_dump($a, $p); }
viaRefAddr([1, 2]);
viaRefCell([1, 2]);
// the rebuild co-owns, nothing leaks per call.
/** @param string[] $p */
function churn(array $p): int { $a = $p; $r = &$a[0]; $r .= 'x'; unset($r); return strlen($a[0]) + strlen($p[0]); }
$n = 0;
for ($i = 0; $i < 1000; $i++) { $n += churn([str_repeat('a', 64), 'b']); }
$b = memory_get_usage();
for ($i = 0; $i < 100000; $i++) { $n += churn([str_repeat('a', 64), 'b']); }
$g = memory_get_usage() - $b;
echo $n, ' ', $g < 1048576 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

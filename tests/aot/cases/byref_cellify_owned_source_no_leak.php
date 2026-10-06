<?php
// @serial: a memory measurement.
// A conversion store between a concrete-element and a cell-element array slot,
// in both directions, from a CALL RESULT (an owned temp) and from a dying
// local: the rebuild co-owns every element and the owned source is released
// exactly once.
/** @return string[] */
function mk(int $i): array { return [str_repeat('a', 100) . $i, str_repeat('b', 100)]; }
/** @return array<int, mixed> */
function mkc(int $i): array { return [str_repeat('c', 100) . $i, 7]; }
function fwdCall(int $i): int { $a = mk($i); $r = &$a[0]; $r .= 'x'; unset($r); return strlen($a[0]); }
function fwdLocal(int $i): int { $s = mk($i); $a = $s; $r = &$a[0]; $r .= 'x'; unset($r); return strlen($a[0]); }
function backCall(int $i): int { /** @var string[] $t */ $t = mkc($i); return strlen($t[0]); }
function backLocal(int $i): int { $c = mkc($i); /** @var string[] $t */ $t = $c; return strlen($t[0]); }
$n = 0;
for ($i = 0; $i < 1000; $i++) { $n += fwdCall($i) + fwdLocal($i) + backCall($i) + backLocal($i); }
$out = [];
foreach (['fwdCall', 'fwdLocal', 'backCall', 'backLocal'] as $f) {
    $b = memory_get_usage();
    for ($i = 0; $i < 100000; $i++) { $n += $f($i); }
    $g = memory_get_usage() - $b;
    $out[] = $f . ' ' . ($g < 1048576 ? 'ok' : round($g / 1048576, 1) . 'MB');
}
echo $n, ' ', implode(', ', $out), "\n";

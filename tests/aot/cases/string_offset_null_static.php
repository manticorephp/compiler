<?php
// A statically-null string offset takes php's `String offset cast occurred`
// like a bool or float one does (thrown here, where php warns and reads
// offset 0), on read and write; isset / empty / `??` cast silently as in php.
// Expected output is hand-written: php prints a Warning and "a" instead of the
// TypeError lines.

function probe(string $label, callable $f): void
{
    try { $r = $f(); echo $label, ': ', $r, "\n"; } catch (\TypeError $e) { echo $label, ': ', \get_class($e), ' ', $e->getMessage(), "\n"; }
}

$s = 'abc';
$n = null;
probe('read local', function () use ($s, $n) { return $s[$n]; });
probe('read literal', function () use ($s) { return $s[null]; });
probe('write local', function () use ($s, $n) { $s[$n] = 'x'; return $s; });
probe('read bool', function () use ($s) { $b = true; return $s[$b]; });
echo 'isset: ', var_export(isset($s[$n]), true), "\n";
echo 'empty: ', var_export(empty($s[$n]), true), "\n";
echo 'coalesce: ', $s[$n] ?? 'd', "\n";
echo 'isset literal: ', var_export(isset($s[null]), true), "\n";

function rd(string $s): string { $k = null; return $s[$k]; }
function wr(string $s): string { $k = null; $s[$k] = 'y'; return $s; }
probe('fn read', fn () => rd('pq'));
probe('fn write', fn () => wr('pq'));

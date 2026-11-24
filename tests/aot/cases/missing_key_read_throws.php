<?php
// Every READ php warns on throws its warning text as OutOfBoundsException (#718, #809):
// typed, erased and cell-keyed bases, list(), compound assignment, string offsets (also the
// byte path `ord($s[$i])` / `$s[$i] === 'x'`). Fetches for WRITING (`$a['x'][] = 1`, unset,
// `&$a['x']`, foreach by reference) and probes (isset, `??`, empty) stay quiet.
function t(string $label, callable $f): void
{
    try { $f(); echo $label, " ok\n"; }
    catch (\OutOfBoundsException $e) { echo $label, ": ", $e->getMessage(), "\n"; }
}
/** @param array<string, int> $m */
function typed(array $m, string $k): int { return $m[$k]; }
function erased(mixed $m, mixed $k): mixed { return $m[$k]; }
function byte(string $s, int $i): int { return ord($s[$i]); }
function isX(string $s, int $i): bool { return $s[$i] === 'x'; }
class Box { public static array $m = []; public array $p = []; }

t('typed', fn () => typed(['a' => 1], 'b'));
t('typed-hit', fn () => typed(['a' => 0], 'a'));
t('erased-int', fn () => erased([1, 2], 5));
t('erased-str', fn () => erased(['a' => null], 'zz'));
t('erased-null-elem', function () { var_dump(erased(['a' => null], 'a')); });
t('erased-string', fn () => erased('abc', 9));
t('numeric-string-key', fn () => erased([1, 2], '9'));
t('nested-inner', function () { $a = ['x' => []]; echo $a['x']['y']; });
t('nested-outer', function () { $a = []; echo $a['x']['y']; });
t('list', function () { [$p, $q] = [1]; });
t('list-keyed', function () { ['a' => $p] = ['b' => 1]; });
t('compound', function () { $a = []; $a['n'] += 1; });
t('inc', function () { $a = ['x' => []]; $a['x']['n']++; });
t('byte', fn () => byte('ab', 2));
t('byte-neg', fn () => byte('ab', -3));
t('is-x', fn () => isX('ab', 5));
t('byte-hit', function () { echo byte('ab', -1), isX('xy', 0) ? 'X' : '-', "\n"; });
t('write-nested', function () { $a = []; $a['x'][] = 1; $a['y']['z'] = 2; $a['w']['v'] ??= 3; echo count($a), "\n"; });
t('write-unset', function () { $a = []; unset($a['x']['y']); echo count($a), "\n"; });
t('write-ref', function () { $a = []; $r = &$a['x']['y']; $r = 1; echo $a['x']['y'], "\n"; });
t('write-foreach-ref', function () { $a = []; foreach ($a['l'] ?? [] as $v) {} $a['l'] = [1]; foreach ($a['l'] as &$v) { $v++; } unset($v); echo $a['l'][0], "\n"; });
t('write-prop', function () { $o = new Box; $o->p['a']['b'] = 1; Box::$m['k'][] = 2; echo $o->p['a']['b'], count(Box::$m['k']), "\n"; });
t('probe', function () { $a = ['x' => null]; echo isset($a['y']['z']) ? 1 : 0, empty($a['y']) ? 1 : 0, $a['y']['z'] ?? 'd', "\n"; });

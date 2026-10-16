<?php
// A cell-typed (union) value used as the format of sprintf/printf, with and without reassign.
function a(string|bool|null $f = null, string $e = 'a'): string { return sprintf($f, $e); }
function b(string|bool|null $f = null, string $e = 'a'): string {
    if (true === $f) { $f = '<%s>'; }
    return sprintf($f, $e);
}
function c(int|string $f, int $n): string { return sprintf($f, $n); }
function d(mixed $f): void { printf($f, 7); echo "\n"; }
function e(string|null $f): string {
    $loc = $f;
    if ($loc === null) { $loc = '%s!'; }
    return sprintf($loc, 'z') . strlen($loc) . str_replace('%', '#', $loc) . implode(',', [$loc]);
}
echo a('[%s]'), b(true), b('{%s}'), c('%03d', 5), c(7, 1), "\n";
d('n=%d');
echo e(null), e('q%s'), "\n";

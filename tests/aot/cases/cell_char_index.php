<?php

// `$cell[$i]`: a cell subject is string-or-array only at runtime, so the index
// must dispatch on the NaN tag. It used to fall straight to the array path and
// deref a string pointer as an array — SIGSEGV.

function mkstr(int $n): string|false
{
    if ($n === 0) {
        return false;
    }
    return 'hello';
}

/** @return array<int,string>|false */
function mkarr(int $n): array|false
{
    if ($n === 0) {
        return false;
    }
    return ['zero', 'one', 'two'];
}

function mixed_val(int $n): mixed
{
    if ($n === 0) {
        return 'abc';
    }
    if ($n === 1) {
        return [10, 20, 30];
    }
    return 5;
}

echo "-- string cell, char index --\n";
$s = mkstr(1);
var_dump(strlen($s));
var_dump($s[0]);
var_dump($s[1]);
var_dump($s[4]);
$i = strlen($s) - 1;
var_dump($s[$i]);

echo "-- string cell, negative index --\n";
var_dump($s[-1]);
var_dump($s[-5]);

echo "-- array cell, int index --\n";
$a = mkarr(1);
var_dump($a[0]);
var_dump($a[2]);
$j = 1;
var_dump($a[$j]);

echo "-- mixed subject, both shapes --\n";
$m0 = mixed_val(0);
var_dump($m0[0]);
var_dump($m0[2]);
$m1 = mixed_val(1);
var_dump($m1[0]);
var_dump($m1[2]);

echo "-- index expression evaluated once --\n";
$calls = 0;
function idx(int &$calls): int
{
    $calls = $calls + 1;
    return 1;
}
$s2 = mkstr(1);
var_dump($s2[idx($calls)]);
var_dump($calls);

echo "-- statically-typed string still works --\n";
function plain(string $x): string
{
    return $x;
}
$p = plain('world');
var_dump($p[0]);
var_dump($p[4]);

echo "-- statically-typed array still works --\n";
$pa = [7, 8, 9];
var_dump($pa[0]);
var_dump($pa[2]);

echo "-- cell string in a loop --\n";
$acc = '';
$src = mkstr(1);
$n = strlen($src);
for ($k = 0; $k < $n; $k = $k + 1) {
    $acc = $acc . $src[$k] . '.';
}
var_dump($acc);

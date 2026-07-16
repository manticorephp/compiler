<?php

// `===` / `!==` between two statically-different scalar kinds. int/float/bool
// all ride the same raw carrier, so a carrier compare made `0 === false`,
// `1 === true` and `0 === 0.0` all true. PHP: a strict compare of different
// types is always false, whatever the values.

function mi(int $n): int { return $n; }
function mf(float $f): float { return $f; }
function ms(string $s): string { return $s; }
function mb(bool $b): bool { return $b; }

$i0 = mi(0);
$i1 = mi(1);
$f0 = mf(0.0);
$f1 = mf(1.0);
$s0 = ms('0');
$se = ms('');
$bf = mb(false);
$bt = mb(true);

echo "-- int vs bool --\n";
var_dump($i0 === false);
var_dump($i0 !== false);
var_dump($i0 === $bf);
var_dump($i1 === true);
var_dump($i1 === $bt);
var_dump($i1 !== $bt);

echo "-- int vs float --\n";
var_dump($i0 === $f0);
var_dump($i1 === $f1);
var_dump($i1 !== $f1);

echo "-- float vs bool --\n";
var_dump($f0 === false);
var_dump($f1 === true);

echo "-- int vs string --\n";
var_dump($i0 === $s0);

echo "-- string vs bool --\n";
var_dump($se === $bf);

echo "-- vs null --\n";
var_dump($i0 === null);
var_dump($bf === null);
var_dump($se === null);
var_dump($f0 === null);

echo "-- same-type compares still work --\n";
var_dump($i0 === mi(0));
var_dump($i0 === mi(1));
var_dump($i1 !== mi(0));
var_dump($bf === mb(false));
var_dump($bf === mb(true));
var_dump($se === ms(''));
var_dump($se === ms('x'));
var_dump($f0 === mf(0.0));
var_dump($f0 === mf(1.0));

echo "-- side effects on a folded compare still happen --\n";
$log = [];
function tap(array &$log, int $v): int { $log[] = $v; return $v; }
var_dump(tap($log, 7) === false);
var_dump(count($log));
var_dump($log[0]);

echo "-- strrpos/strripos php.net false semantics --\n";
var_dump(strrpos('abc', 'z'));
var_dump(strrpos('abc', 'z') === false);
var_dump(strrpos('.hidden', '.'));
var_dump(strrpos('.hidden', '.') === false);
var_dump(strrpos('.hidden', '.') !== false);
var_dump(strrpos('a.b.c', '.'));
var_dump(strripos('abc', 'Z'));
var_dump(strripos('aXbXc', 'x'));
var_dump(strrpos('', 'x'));
var_dump(strrpos('abc', ''));

echo "-- strpos parity (unchanged) --\n";
var_dump(strpos('abc', 'z'));
var_dump(strpos('abc', 'z') === false);
var_dump(strpos('abc', 'a'));
var_dump(strpos('abc', 'a') === false);
var_dump(stripos('abc', 'Z'));

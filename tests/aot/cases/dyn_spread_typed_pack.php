<?php
// A function named at run time, called with a spread of a TYPED pack (int[],
// string[], float[], bool[], object[],) that supplies every
// parameter: stdlib, prelude and user callees, coercion into their parameters,
// a returned element outliving the pack, a TypeError from a bad element.
final class P { public function __construct(public int $v) {} }

function add2(int $a, int $b): int { return $a + $b; }
function join2(string $a, string $b): string { return $a . '|' . $b; }
function first2(string $a, string $b): string { return $a; }
function asStr(string $a, string $b): string { return $a . '/' . $b; }
function half(float $f): float { return $f / 2; }
function flags(bool $a, bool $b): string { return var_export($a, true) . ',' . var_export($b, true); }
function sumP(P $a, P $b): int { return $a->v + $b->v; }

function callI(string $f, int ...$a): mixed { return $f(...$a); }
function callS(string $f, string ...$a): mixed { return $f(...$a); }
function callF(string $f, float ...$a): mixed { return $f(...$a); }
function callB(string $f, bool ...$a): mixed { return $f(...$a); }
function callP(string $f, P ...$a): mixed { return $f(...$a); }

foreach (['add2', 'asStr', 'array_fill_keys'] as $n) {
    var_dump($n === 'array_fill_keys' ? callI('chr', 65) : callI($n, 7, 5));
}
foreach (['join2', 'first2', 'strcmp', 'str_contains', 'explode'] as $n) {
    var_dump(callS($n, 'a,b', ','));
}
foreach (['half', 'sqrt'] as $n) {
    var_dump(callF($n, 6.25));
}
var_dump(callB('flags', true, false));
var_dump(callP('sumP', new P(2), new P(40)));
$keep = callS('first2', str_repeat('k', 3), 'z');
var_dump($keep);
try {
    var_dump(callS('add2', 'nope', '1'));
} catch (\TypeError $e) {
    echo get_class($e), "\n";
}

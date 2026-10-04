<?php
declare(strict_types=1);

// php's three-way compare (ZEND_THREEWAY_COMPARE) answers 1 — uncomparable —
// when a NAN is on either side: `NAN <=> 1.0` was 0 ("equal"), so `<=`/`>=`
// held. A NAN against a NON-numeric string is 1 in both orders too. A bool
// beside a number compares as two bools (`true <=> 5` is 0). And php spells
// the non-finite floats INF / -INF when they become strings.

function c(mixed $a, mixed $b): string
{
    return ($a <=> $b) . ',' . (int)($a < $b) . (int)($a <= $b) . (int)($a > $b) . (int)($a >= $b) . (int)($a == $b);
}

function ff(float $a, float $b): string { return (string)($a <=> $b); }
function bi(bool $a, int $b): string { return ($a <=> $b) . ',' . ($b <=> $a); }
function bf(bool $a, float $b): string { return ($a <=> $b) . ',' . ($b <=> $a); }
function s(float $f): string { return (string)$f; }

foreach ([1.0, 0, '5', 'abc', '', null, true, false, NAN] as $v) {
    echo \var_export($v, true), ': ', c(NAN, $v), ' | ', c($v, NAN), "\n";
}
echo ff(NAN, 1.0), ff(1.0, NAN), ff(NAN, NAN), ff(2.0, 2.0), ff(1.0, 2.0), "\n";
echo bi(true, 5), ' ', bi(false, 0), ' ', bi(true, 0), ' ', bi(false, -3), "\n";
echo bf(true, 0.5), ' ', bf(false, 0.0), ' ', bf(true, NAN), "\n";
echo s(INF), ' ', s(-INF), ' ', s(1e20), ' ', s(1.5e-7), ' ', s(0.1), "\n";
echo c(INF, 'abc'), ' ', c('abc', -INF), ' ', c(INF, 'INF'), "\n";

// The same rules on the ordering / equality operators: a bool beside an int
// orders as two bools, `NAN != x` is true (the negation of `==`), and two NAN
// cells are neither `==` nor `===`.
function ob(bool $a, int $b): string { return (int)($a < $b) . (int)($a <= $b) . (int)($a > $b) . (int)($a == $b) . (int)($b < $a) . (int)($b >= $a); }
function of(float $a, float $b): string { return (int)($a < $b) . (int)($a <= $b) . (int)($a > $b) . (int)($a >= $b) . (int)($a == $b) . (int)($a != $b); }
function om(mixed $a, mixed $b): string { return (int)($a == $b) . (int)($a != $b) . (int)($a === $b) . (int)($a !== $b); }
echo ob(true, 5), ' ', ob(false, 0), ' ', ob(true, 0), ' ', ob(false, -3), "\n";
echo of(NAN, 1.0), ' ', of(1.0, NAN), ' ', of(NAN, NAN), ' ', of(0.0, -0.0), "\n";
echo om(NAN, NAN), ' ', om(NAN, 1.0), ' ', om(0.0, -0.0), ' ', om(1.5, 1.5), "\n";

<?php
// A by-value captured array: each call starts from the value at creation; stores stay local to the call.
function twice(array $p) {
    $fn = function ($x) use ($p) { $p[] = $x; $p['k'] = $x; return count($p) . ':' . implode(',', $p); };
    return [$fn(1), $fn(2), count($p)];
}
echo implode(' | ', twice(['a'])), "\n";

function outerMutates(array $p) {
    $fn = function () use ($p) { $p[] = 'in'; return implode(',', $p); };
    $p[] = 'out1';
    $first = $fn();
    $p[] = 'out2';
    return $first . ' / ' . $fn() . ' / ' . implode(',', $p);
}
echo outerMutates(['a']), "\n";

function makeCounter(array $seed) {
    return function () use ($seed) { $seed[] = 'x'; return count($seed); };
}
$c = makeCounter(['a', 'b']);
echo $c(), $c(), $c(), "\n";

function nested(array $p) {
    $outer = function () use ($p) {
        $p['o'] = 1;
        $inner = function () use ($p) { $p['i'] = 2; return implode(',', array_keys($p)); };
        return $inner() . ' ' . $inner() . ' ' . implode(',', array_keys($p));
    };
    return $outer() . ' | ' . $outer() . ' | ' . implode(',', array_keys($p));
}
echo nested(['z' => 0]), "\n";

$typed = [1, 2, 3];
$f = function () use ($typed) { $typed[] = count($typed); return array_sum($typed); };
echo $f(), ' ', $f(), ' ', array_sum($typed), "\n";

$grow = function (array $p) { $g = function () use ($p) { for ($i = 0; $i < 40; $i++) { $p['k' . $i] = $i; } return count($p); }; return $g() . $g(); };
echo $grow(['a' => 1]), "\n";

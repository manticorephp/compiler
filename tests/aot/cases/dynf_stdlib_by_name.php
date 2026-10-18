<?php
// Stdlib functions called by runtime name: from user sites, from prelude sites,
// with defaults, variadics, spreads, by-ref params, and a name that matches nothing.

function pick(string $n): string { return $n; }

function userTwo($a, $b = 'B') { return "user($a,$b)"; }

$names = ['str_pad', 'STR_REPEAT', '\trim', 'Substr', 'wordwrap', 'userTwo'];
foreach ($names as $n) {
    $f = pick($n);
    try {
        echo $f('ab', 3), "\n";
    } catch (\Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$f = pick('str_pad');
echo $f('x', 5, '-', STR_PAD_LEFT), "|", $f('y', 3), "|\n";

$f = pick('sprintf');
echo $f('%s-%s-%s-%s-%s-%s-%s-%s-%s', 1, 2, 3, 4, 5, 6, 7, 8, 9), "\n";
echo $f('%05.1f %s', 3.14159, 'pi'), "\n";

$f = pick('implode');
$args = [',', ['a', 'b', 'c']];
echo $f(...$args), "\n";

$f = pick('preg_match');
$m = [];
echo $f('/(\d+)/', 'abc 42', $m), ' ', $m[1], "\n";

$f = pick('userTwo');
echo $f('only'), "\n";

$f = pick('no_such_fn_xyz');
try {
    $f(1);
} catch (\Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo call_user_func(pick('str_repeat'), 'ha', 2), "\n";
echo call_user_func_array(pick('str_pad'), ['z', 4, '*']), "\n";
echo call_user_func_array(pick('userTwo'), ['p']), "\n";

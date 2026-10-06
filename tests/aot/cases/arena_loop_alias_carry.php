<?php

// A loop-local arena string copied into another local that the NEXT iteration
// reads (`$prev = $name`): the per-iteration arena reset must not free it.
function chain(int $n): string
{
    $out = '';
    $prev = '%t0';
    for ($q = 1; $q < $n; $q = $q + 1) {
        $name = $q === $n - 1 ? '%last' : '%x' . (string)$q;
        $out .= '  ' . $name . ' = xor ' . $prev . ', %t' . (string)$q . "\n";
        $prev = $name;
    }
    return $out;
}
function pick(array $words): string
{
    $best = '';
    foreach ($words as $w) {
        $cur = strtoupper($w) . '!';
        if (strlen($cur) > strlen($best)) { $best = $cur; }
    }
    return $best;
}
echo chain(8);
echo pick(['ab', 'abcd', 'abc', 'a']), "\n";

<?php

// The init VALUE, pinned against the oracle. A bare `&$x` parameter carries no
// promise that the callee writes it — unlike `#[RefOut]`, which is exactly such
// a promise and may therefore vivify an empty ARRAY. php leaves the variable
// NULL when the callee skips the write, so this must print NULL, never
// `array(0) {}`.

function maybe(bool $do, ?array &$out): string
{
    if ($do) {
        $out = [1, 2, 3];
    }
    return $do ? 'wrote' : 'skipped';
}

echo maybe(false, $skipped), "\n";
var_dump($skipped);
var_dump(is_null($skipped));

echo maybe(true, $wrote), "\n";
var_dump($wrote);

function maybeInt(bool $do, ?int &$n): string
{
    if ($do) {
        $n = 42;
    }
    return $do ? 'wrote' : 'skipped';
}

echo maybeInt(false, $noInt), "\n";
var_dump($noInt);
echo maybeInt(true, $yesInt), "\n";
var_dump($yesInt);

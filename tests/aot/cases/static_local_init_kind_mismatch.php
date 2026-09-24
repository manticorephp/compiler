<?php

// An INITIALISED static local whose later stores are of another kind: the slot
// holds both, so it is a cell and the initialiser is boxed into it. It was typed
// by the initialiser alone, stored a NaN-boxed value, and read it back raw.

function cached(bool $reset = false): string
{
    static $v = '';
    if ($reset) { $v = ''; return ''; }
    if ($v === '') {
        $c = \getenv('MC_NO_SUCH_VAR_42') !== false ? false : \str_repeat('ab', 3);
        $v = $c === false ? '' : $c;
    }
    return $v;
}

function counter(): int|string
{
    static $n = 0;
    $n = $n === 2 ? 'two' : ($n === 'two' ? 3 : $n + 1);
    return $n;
}

echo cached(), '|', cached(), "\n";
cached(true);
echo cached(), "\n";
for ($i = 0; $i < 4; $i++) { echo counter(), ' '; }
echo "\n";

<?php

// The same shape inside a generator body. A generator's locals live in a heap
// frame across suspensions, so an entry init must run once when the body
// starts — not again on every resume, which would wipe what the previous
// iteration wrote through the reference.

function fill(int $i, ?array &$out): int
{
    $out = [$i, $i + 100];
    return $i;
}

function gen(int $n)
{
    for ($i = 0; $i < $n; $i++) {
        fill($i, $slot);
        yield $slot[1];
        // $slot must still hold what fill() wrote before the suspension.
        yield $slot[0];
    }
}

foreach (gen(3) as $v) {
    echo $v, "\n";
}

function genOnce()
{
    fill(9, $only);
    yield 'a';
    var_dump($only);
    yield 'b';
}

foreach (genOnce() as $v) {
    echo $v, "\n";
}

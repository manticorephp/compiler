<?php

// The init belongs at function ENTRY, not before the call. A call inside a
// loop whose out-var is read after the loop must see the LAST write, and a
// per-call init would null it on every iteration; an init emitted inside the
// loop body would also be a per-iteration stack allocation.

function step(int $i, ?array &$out): int
{
    $out = ['i' => $i, 'sq' => $i * $i];
    return $i;
}

function run(): void
{
    for ($i = 0; $i < 4; $i++) {
        step($i, $last);
    }
    var_dump($last);
    var_dump($last['sq']);
}

run();

function accumulateThroughRef(): void
{
    $total = 0;
    foreach ([2, 3, 5] as $n) {
        step($n, $seen);
        $total = $total + $seen['sq'];
    }
    echo $total, "\n";
}

accumulateThroughRef();

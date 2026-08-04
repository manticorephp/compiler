<?php

// The pass must SKIP a local that is defined anywhere else in the function —
// the entry init is sound only because there is nothing to clobber. Here the
// assignment comes AFTER the call, so a flow-insensitive "is it defined at
// all" is the right question and a naive "is it defined YET" would plant an
// init that the later store then overwrites (harmless) — or, worse, an init
// placed at the CALL that clobbers a value the loop below carries.

function bump(int $a, ?array &$out): int
{
    $out = [$a];
    return $a;
}

function later(): void
{
    $x = ['pre-set'];
    echo bump(1, $x), "\n";
    var_dump($x);
    $x = ['post-set'];
    var_dump($x);
}

later();

function carried(): void
{
    $acc = [];
    for ($i = 0; $i < 3; $i++) {
        $acc[] = $i;
        echo bump($i, $acc), "\n";
        var_dump(count($acc));
    }
}

carried();

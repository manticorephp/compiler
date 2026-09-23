<?php
// A loop-carried local stored as int before the loop and from an erased/cell source inside it must
// keep one representation at every load, including the loop header — also when it is used as a key.

function coalesce_chain(): void {
    $head = []; $prev = [];
    $prev[0] = $head[7] ?? -1;
    $head[7] = 0;
    $prev[1] = $head[7] ?? -1;
    $head[7] = 1;
    $cand = $head[7] ?? -1;
    $chain = 5;
    while ($cand >= 0 && $chain > 0) {
        echo "visit $cand\n";
        $cand = $prev[$cand] ?? -1;
        $chain = $chain - 1;
    }
}

function ternary_chain(): void {
    $head = []; $prev = [];
    $prev[0] = $head[7] ?? -1;
    $head[7] = 0;
    $prev[1] = $head[7] ?? -1;
    $cand = 1;
    $chain = 5;
    while ($cand >= 0 && $chain > 0) {
        echo "ternary $cand\n";
        $cand = isset($prev[$cand]) ? $prev[$cand] : -1;
        $chain--;
    }
}

function elvis_chain(): void {
    $head = []; $prev = [];
    $prev[0] = $head[7] ?? -1;
    $head[7] = 0;
    $prev[1] = $head[7] ?? -1;
    $prev[2] = 1;
    $cand = 2;
    $chain = 5;
    while ($cand > 0 && $chain > 0) {
        echo "elvis $cand\n";
        $cand = $prev[$cand] ?: -1;
        $chain--;
    }
}

function match_chain(): void {
    $head = []; $prev = [];
    $prev[0] = $head[7] ?? -1;
    $head[7] = 0;
    $prev[1] = $head[7] ?? -1;
    $cand = 1;
    for ($chain = 5; $cand >= 0 && $chain > 0; $chain--) {
        echo "match $cand\n";
        $cand = match (true) {
            isset($prev[$cand]) => $prev[$cand],
            default => -1,
        };
    }
}

function coalesce_assign_chain(): void {
    $head = []; $prev = [];
    $prev[0] = $head[7] ?? -1;
    $head[7] = 0;
    $prev[1] = $head[7] ?? -1;
    $cand = 1;
    $chain = 5;
    while ($cand >= 0 && $chain > 0) {
        echo "coalesce-assign $cand\n";
        $next = $prev[$cand] ?? null;
        $next ??= -1;
        $cand = $next;
        $chain--;
    }
}

function plain_read_chain(): void {
    $head = []; $prev = [];
    $prev[0] = $head[7] ?? -1;
    $head[7] = 0;
    $prev[1] = $head[7] ?? -1;
    $prev[2] = 1;
    $cand = 2;
    $chain = 5;
    while ($cand >= 0 && $chain > 0) {
        echo "plain $cand\n";
        $cand = $prev[$cand];
        $chain--;
    }
}

function foreach_carried(): void {
    $head = []; $prev = [];
    $prev[0] = $head[7] ?? -1;
    $head[7] = 0;
    $prev[1] = $head[7] ?? -1;
    $cand = 1;
    foreach ([1, 2, 3] as $_) {
        echo "foreach $cand\n";
        if ($cand < 0) break;
        $cand = $prev[$cand] ?? -1;
    }
}

function null_seeded_key(): void {
    $map = [0 => 'a', 1 => 'b', 2 => 'c'];
    $k = null;
    for ($i = 0; $i < 3; $i++) {
        echo "null-seed ", $k === null ? 'null' : $map[$k], "\n";
        $k = $i;
    }
}

function rekinded_key(): void {
    $m = [0 => 'zero', 'x' => 'ex', 'y' => 'why'];
    $k = 0;
    foreach (['x', 'y'] as $s) {
        echo "rekind ", $m[$k], "\n";
        $k = $s;
    }
    echo "rekind ", $m[$k], "\n";
}

function do_while_chain(): void {
    $head = []; $prev = [];
    $prev[0] = $head[7] ?? -1;
    $head[7] = 0;
    $prev[1] = $head[7] ?? -1;
    $cand = 1;
    do {
        echo "do $cand\n";
        $cand = $prev[$cand] ?? -1;
    } while ($cand >= 0);
}

function unset_carried_key(): void {
    $head = []; $prev = [];
    $prev[0] = $head[7] ?? -1;
    $head[7] = 0;
    $prev[1] = $head[7] ?? -1;
    $v = [10, 20, 30, 40];
    $k = 1;
    while ($k >= 0) {
        unset($v[$k]);
        $k = $prev[$k] ?? -1;
    }
    echo "unset ", json_encode($v), "\n";
}

coalesce_chain();
ternary_chain();
elvis_chain();
match_chain();
coalesce_assign_chain();
plain_read_chain();
foreach_carried();
null_seeded_key();
rekinded_key();
do_while_chain();
unset_carried_key();

<?php
// Arena arrays + per-iteration loop reset (liveness-gated). Exercises: an
// iteration-local scratch array (reset each iter), a bound array read AFTER the
// loop (must NOT reset), an accumulator (must NOT reset), and a grow-built
// empty-literal vec. Output must match the php interpreter exactly.

function scratch(int $n): int {           // rebuilt each iter, used within -> reset
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $a = [$i, $i * 2, $i * 3];
        $s += $a[0] + $a[1] + $a[2];
    }
    return $s;
}

function survive(int $n): int {           // bound value read AFTER loop -> no reset
    $last = [0, 0];
    for ($i = 0; $i < $n; $i++) { $last = [$i, $i + 100]; }
    return $last[0] + $last[1];
}

function grow(int $n): int {              // empty-literal + append (arena grow), reset each rep
    $t = 0;
    for ($rep = 0; $rep < 3; $rep++) {
        $a = [];
        for ($i = 0; $i < $n; $i++) { $a[] = $i; }
        for ($i = 0; $i < $n; $i++) { $t += $a[$i]; }
    }
    return $t;
}

function accum(int $n): int {             // accumulator used after loop -> no reset
    $a = [];
    for ($i = 0; $i < $n; $i++) { $a[] = $i * $i; }
    $s = 0;
    foreach ($a as $v) { $s += $v; }
    return $s;
}

echo scratch(5), "\n";
echo survive(5), "\n";
echo grow(100), "\n";
echo accum(50), "\n";

<?php
// Direct-invoked arrow closure in a hot loop (manticore inlines + LLVM folds).
// Trip count seeded from $argc so the loop isn't constant-folded away.
$adder = fn(int $a, int $b) => $a + $b;
$sum = 0;
$n = 100000000 * $argc;
for ($i = 0; $i < $n; $i++) { $sum = $adder($sum, $i & 7); }
echo $sum, "\n";

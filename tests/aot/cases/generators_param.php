<?php
function nums(int $n) { $i = 1; while ($i <= $n) { yield $i; $i = $i + 1; } }
function sum_gen(Generator $g): int {
    $t = 0;
    foreach ($g as $v) { $t = $t + $v; }
    return $t;
}
echo sum_gen(nums(5)), "\n";

function consume(Generator $g): void {
    foreach ($g as $w) { echo strtoupper($w), "\n"; }
}
function words() { yield "a"; yield "bb"; yield "ccc"; }
consume(words());

function dbl(Generator $g): void { foreach ($g as $x) { echo $x * 2, " "; } echo "\n"; }
dbl(nums(3));

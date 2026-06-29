<?php
function count_to(int $n) {
    $i = 1;
    while ($i <= $n) {
        yield $i;
        $i = $i + 1;
    }
}
foreach (count_to(3) as $v) { echo $v, "\n"; }

function squares(int $n) {
    for ($i = 1; $i <= $n; $i = $i + 1) { yield $i * $i; }
}
foreach (squares(4) as $s) { echo $s, " "; }
echo "\n";

function kv() { $i = 0; while ($i < 3) { yield $i; $i = $i + 1; } }
foreach (kv() as $k => $v) { echo $k, "=", $v, "\n"; }

function early() { yield 1; return; yield 99; }
foreach (early() as $v) { echo $v, "\n"; }

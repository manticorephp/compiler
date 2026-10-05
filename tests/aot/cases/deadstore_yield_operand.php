<?php
// A local read only as the operand of `yield` is live: DeadStore dropped its
// store and the verifier rejected the dangling read.
function one() {
    $x = 7;
    yield $x;
}
function pair() {
    $k = 'a';
    $v = 5;
    $v = 6;
    yield $k => $v;
}
function viaFrom() {
    $inner = [1, 2];
    yield from $inner;
}
foreach (one() as $a) { echo $a, "\n"; }
foreach (pair() as $k => $v) { echo $k, "=", $v, "\n"; }
foreach (viaFrom() as $a) { echo $a, "\n"; }

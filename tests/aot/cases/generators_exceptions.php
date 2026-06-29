<?php
// try/catch fully inside a generator (no yield between try and catch).
function g1() {
    try { throw new Exception("boom"); }
    catch (Exception $e) { echo "caught: ", $e->getMessage(), "\n"; }
    yield 1;
    yield 2;
}
foreach (g1() as $v) { echo $v, "\n"; }

// yield inside a try that falls through normally, then a later yield.
function g2() {
    try { yield 1; echo "in try\n"; }
    catch (Exception $e) { echo "caught\n"; }
    yield 2;
}
foreach (g2() as $v) { echo $v, "\n"; }

// $gen->throw() — inject at the suspended yield, caught inside, continue.
function g3() {
    $i = 0;
    while (true) {
        try { $x = yield $i; $i = $i + 1; }
        catch (Exception $e) { echo "caught: ", $e->getMessage(), "\n"; $i = 100; }
    }
}
$gen = g3();
echo $gen->current(), "\n";
echo $gen->send(5), "\n";
echo $gen->throw(new Exception("injected")), "\n";
echo $gen->send(5), "\n";

// Exception propagating OUT of a generator to the foreach consumer.
function g4() { yield 1; throw new Exception("from gen"); }
try {
    foreach (g4() as $v) { echo $v, "\n"; }
} catch (Exception $e) {
    echo "consumer caught: ", $e->getMessage(), "\n";
}

// try/finally with yields inside, normal fall-through.
function g5() {
    try { yield 1; yield 2; }
    finally { echo "cleanup\n"; }
    yield 3;
}
foreach (g5() as $v) { echo $v, "\n"; }

// Multiple catches + finally; nested try inside the generator.
class AppError extends Exception {}
function g6() {
    try {
        yield 1;
        try { yield 2; throw new AppError("inner"); }
        catch (RuntimeException $e) { echo "rt\n"; }
        catch (AppError $e) { echo "app: ", $e->getMessage(), "\n"; }
        yield 3;
    } finally { echo "outer fin\n"; }
}
foreach (g6() as $v) { echo $v, "\n"; }

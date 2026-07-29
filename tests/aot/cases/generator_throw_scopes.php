<?php
// Where a `$gen->throw()` lands, and where a throw after a resume lands.
//
// The injection used to longjmp to `@__mir_jmp_depth - 1` — the innermost try of
// whoever CALLED throw(), not the generator's own. With a try around the call the
// consumer's catch stole the exception (wrong on every host); with no try there,
// the slot happened to be the generator's own jmp_buf, captured by a setjmp in the
// invocation that entered the try — a stack frame destroyed when the yield
// returned. arm64 tolerated the dead frame; x86_64 turned it into a SIGSEGV as
// soon as the catch body allocated dynamically, which a virtual dispatch does.
//
// Plain PHP throughout, so `php` is the oracle for all of it.

// A user subclass makes $e->getMessage() a VIRTUAL call, which is what brought the
// dynamic alloca that turned the dead frame into a crash.
class AppError extends Exception {}

function counter() {
    $i = 0;
    while (true) {
        try { $x = yield $i; $i = $i + 1; }
        catch (Exception $e) { echo 'inner caught: ', $e->getMessage(), "\n"; $i = 100; }
    }
}

// 1. The consumer is inside its OWN try: the generator's catch must still win.
$gen = counter();
echo $gen->current(), "\n";
try {
    echo $gen->throw(new Exception('injected')), "\n";
} catch (Exception $c) {
    echo "consumer caught (WRONG): ", $c->getMessage(), "\n";
}

// 2. No try around the call — the same catch, reached the other way.
$g2 = counter();
echo $g2->current(), "\n";
echo $g2->throw(new AppError('second')), "\n";

// 3. A generator with NO catch of its own: now the consumer SHOULD get it.
function bare() { $i = 0; while (true) { $x = yield $i; $i = $i + 1; } }
$g3 = bare();
echo $g3->current(), "\n";
try {
    echo $g3->throw(new Exception('uncaught inside')), "\n";
} catch (Exception $c) {
    echo 'consumer caught: ', $c->getMessage(), "\n";
}

// 4. A throw raised by the generator body itself after a resume, caught inside.
function selfThrow() {
    $n = 0;
    while (true) {
        try {
            $n = yield $n;
            if ($n > 0) { throw new AppError('self ' . (string)$n); }
        } catch (AppError $e) {
            echo 'self caught: ', $e->getMessage(), "\n";
            $n = -1;
        }
    }
}
$g4 = selfThrow();
echo $g4->current(), "\n";
echo $g4->send(7), "\n";

// 5. try/finally around the yield: the finally must run on the way out.
function withFinally() {
    try { yield 1; yield 2; }
    finally { echo "finally ran\n"; }
}
$g5 = withFinally();
echo $g5->current(), "\n";
try { $g5->throw(new Exception('through finally')); }
catch (Exception $e) { echo 'after finally: ', $e->getMessage(), "\n"; }

// 6. Nested trys inside the generator: the INNER catch wins.
function nested() {
    while (true) {
        try {
            try { yield 1; }
            catch (AppError $e) { echo "inner\n"; }
        } catch (Exception $e) { echo "outer\n"; }
        yield 2;
    }
}
$g6 = nested();
echo $g6->current(), "\n";
echo $g6->throw(new AppError('pick inner')), "\n";

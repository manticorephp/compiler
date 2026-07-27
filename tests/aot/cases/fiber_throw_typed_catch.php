<?php
// Fiber::throw() delivering to a TYPED catch. The injected throwable used to be
// held in a `mixed` field, i.e. NaN-boxed; a `catch (RuntimeException $e)`
// class-id test then dereferenced the boxed word as an object header and
// segfaulted. `catch (\Throwable)` needs no class test, which is why the plain
// fiber_throw case never caught this.
function inner() { \Fiber::suspend(); echo "not reached\n"; }

$f = new Fiber(function () {
    try {
        inner();
    } catch (\LogicException $e) {
        echo "wrong arm\n";
    } catch (\RuntimeException $e) {
        echo "typed catch: " . $e->getMessage() . "\n";
    }
    return "ret";
});
$f->start();
$f->throw(new \RuntimeException("injected"));
var_dump($f->isTerminated());
echo $f->getReturn() . "\n";

// The same for an UNCAUGHT throwable re-raised in the resumer (pendingEx).
$g = new Fiber(function () {
    \Fiber::suspend();
    throw new \RuntimeException("from fiber");
});
$g->start();
try {
    $g->resume();
} catch (\RuntimeException $e) {
    echo "resumer typed catch: " . $e->getMessage() . "\n";
}

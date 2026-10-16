<?php
// A suspended or finished fiber's frames must not stay in the caller's backtrace.

class A { function m(int $d) { if ($d === 0) throw new RuntimeException('x'); return $this->m($d - 1); } }
function h(): int { try { (new A)->m(3); } catch (Exception $e) { return count($e->getTrace()); } return -1; }

echo h(), "\n";
$fs = [];
for ($i = 0; $i < 3; $i++) {
    $fs[] = new Fiber(function () { $a = h(); Fiber::suspend(); $b = h(); echo "fiber $a $b\n"; });
}
foreach ($fs as $f) { $f->start(); }
echo "suspended ", h(), "\n";
foreach ($fs as $f) { $f->resume(); }
echo "finished ", h(), "\n";

// many finished fibers, one suspended forever
for ($i = 0; $i < 5000; $i++) { $f = new Fiber(function () { Fiber::suspend(); }); $f->start(); $f->resume(); }
$keep = new Fiber(function () { Fiber::suspend(); });
$keep->start();
echo "after churn ", h(), "\n";

// nested: the inner fiber's frames sit on top of the outer's
$outer = new Fiber(function () {
    $inner = new Fiber(function () { echo "inner ", h(), "\n"; Fiber::suspend(); echo "inner again ", h(), "\n"; });
    $inner->start();
    echo "outer ", h(), "\n";
    Fiber::suspend();
    $inner->resume();
    echo "outer again ", h(), "\n";
});
$outer->start();
echo "main ", h(), "\n";
function later(Fiber $f): void { $f->resume(); }
later($outer);
echo "main end ", h(), "\n";

function inner(): string { return (new Exception('x'))->getTraceAsString(); }
$t = new Fiber(function () { Fiber::suspend(inner()); return inner(); });
$first = $t->start();
later($t);
echo count(explode("\n", $first)), ' ', count(explode("\n", $t->getReturn())), "\n";

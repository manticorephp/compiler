<?php
// A finally that throws while an exception is pending chains the pending one as
// the deepest `previous` of the new one; a finally that returns discards it.
function chain(): void {
    try { throw new RuntimeException('A'); } finally { throw new LogicException('B', 0, new Exception('B0')); }
}
try { chain(); } catch (Exception $e) {
    for ($x = $e; $x !== null; $x = $x->getPrevious()) { echo get_class($x), ':', $x->getMessage(), "\n"; }
}
function chainCatch(): void {
    try { throw new RuntimeException('C'); } catch (RuntimeException $e) { throw new LogicException('D'); } finally { throw new Exception('E'); }
}
try { chainCatch(); } catch (Exception $e) {
    for ($x = $e; $x !== null; $x = $x->getPrevious()) { echo $x->getMessage(), "\n"; }
}
function discard(): int {
    try { throw new RuntimeException('lost'); } finally { return 4; }
}
echo discard(), "\n";
function discardNested(): int {
    try { try { throw new RuntimeException('lost2'); } finally { return 5; } } finally { echo "outer fin\n"; }
}
echo discardNested(), "\n";
function inner(): void {
    try { throw new RuntimeException('F'); } finally {
        try { throw new LogicException('G'); } catch (LogicException $e) { echo "inner caught G\n"; }
    }
}
try { inner(); } catch (Exception $e) { echo $e->getMessage(), ' prev=', $e->getPrevious() === null ? 'none' : 'some', "\n"; }
final class D { public function __destruct() { echo "D gone\n"; } }
function discardObj(): int {
    try { throw new class('dtor') extends RuntimeException { public D $d; public function __construct(string $m) { parent::__construct($m); $this->d = new D(); } }; }
    finally { return 6; }
}
echo discardObj(), "\n";
// No leak: a discarded or chained pending exception is released.
// @serial: a memory measurement.
for ($i = 0; $i < 2000; $i++) { discard(); try { chain(); } catch (Exception $e) {} }
unset($e);
$b = memory_get_usage();
for ($i = 0; $i < 40000; $i++) { discard(); try { chain(); } catch (Exception $e) {} }
unset($e);
$g = memory_get_usage() - $b;
echo $g < 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

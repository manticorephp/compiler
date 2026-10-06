<?php
// An unwind out of a frame drops its owned locals at every raise point:
// an unmatched catch's re-raise, a finally's re-raise, a throw of an owned local.
final class D { public function __construct(public string $n) {} public function __destruct() { echo "d ", $this->n, "\n"; } }
function a(): void {
    $x = new D('a.x');
    try { throw new RuntimeException('a'); } catch (LogicException $e) { echo "no\n"; }
}
function b(): void {
    $y = new D('b.y');
    try { $z = new D('b.z'); throw new RuntimeException('b'); } finally { echo "fin b\n"; }
}
function c(): void {
    $e = new RuntimeException('c');
    $w = new D('c.w');
    throw $e;
}
function d(int $i): int {
    $v = new D('d.v');
    return intdiv(10, $i) + strlen($v->n);
}
function e(int $i): int {
    $u = new D('e.u');
    $w = 10 % $i;
    echo $w, "\n";
    return strlen($u->n);
}
foreach (['a', 'b', 'c'] as $f) {
    try { $f(); } catch (RuntimeException $e) { echo "caught ", $e->getMessage(), "\n"; }
}
try { d(0); } catch (DivisionByZeroError $e) { echo "caught ", $e->getMessage(), "\n"; }
try { e(0); } catch (DivisionByZeroError $e) { echo "caught ", $e->getMessage(), "\n"; }
echo "end\n";

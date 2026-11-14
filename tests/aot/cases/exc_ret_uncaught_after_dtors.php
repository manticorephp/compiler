<?php
// An uncaught exception runs the destructors of every frame it leaves before the exception handler, then shutdown destructors.
final class D { public function __construct(private string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
function c3(): void { $d = new D('c3'); throw new LogicException('deep'); }
function c2(): void { $d = new D('c2'); c3(); }
function c1(): void { $d = new D('c1'); c2(); }
set_exception_handler(function (Throwable $e) { echo "handler ", get_class($e), " ", $e->getMessage(), "\n"; });
$top = new D('top');
echo "start\n";
c1();
echo "never\n";

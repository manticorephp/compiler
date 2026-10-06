<?php
// An uncaught throw destroys the unwound frames' locals before the handler / fatal runs.
final class D { public function __construct(public string $n) {} public function __destruct() { echo "d ", $this->n, "
"; } }
set_exception_handler(function (Throwable $e) { echo "handler ", $e->getMessage(), "
"; });
function g(): void { $x = new D("g"); $s = str_repeat("x", 3); throw new RuntimeException("boom" . $s); }
function f(): void { $y = new D("f"); g(); echo "unreached
"; }
f();

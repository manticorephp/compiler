<?php
// An uncaught throw whose unwound frame's destructor throws and catches
// internally: the handler still gets the original exception.
final class D {
    public function __destruct() {
        try { throw new LogicException('inner'); } catch (LogicException $e) { echo "dtor caught ", $e->getMessage(), "\n"; }
    }
}
set_exception_handler(function (Throwable $e) { echo "handler ", get_class($e), ' ', $e->getMessage(), "\n"; });
function g(): void { $d = new D(); $s = str_repeat('o', 2); throw new RuntimeException('outer' . $s); }
function f(): void { $x = new D(); g(); }
f();

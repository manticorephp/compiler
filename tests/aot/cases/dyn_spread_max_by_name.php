<?php
// max()/min() called by a run-time name with a spread pack crash with SIGSEGV.
function callM(string $f, mixed ...$a): mixed { return $f(...$a); }
foreach (['max', 'min'] as $n) {
    var_dump(callM($n, 7, 5));
}

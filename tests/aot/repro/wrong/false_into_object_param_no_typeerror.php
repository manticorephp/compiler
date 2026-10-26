<?php
// A false returned by a false|Box function and passed to a Box-typed parameter raises no TypeError (the callee reads null).
// issue: #484
class Box { public int $n = 1; public function get(): int { return $this->n; } }
function make(bool $ok): false|Box { return $ok ? new Box() : false; }
function use_box(Box $b): int { return $b->n; }
try {
    echo use_box(make(false)), "\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', explode(', called in', $e->getMessage())[0], "\n";
}
echo use_box(make(true)), "\n";
// the same false as a method receiver: php's Error names false (natively: "... on null", or a SIGSEGV)
try {
    echo make(false)->get(), "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo make(true)->get(), "\n";

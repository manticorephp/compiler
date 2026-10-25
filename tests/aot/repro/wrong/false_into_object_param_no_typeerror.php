<?php
// A false returned by a false|Box function and passed to a Box-typed parameter raises no TypeError (the callee reads null).
// issue: #484
class Box { public int $n = 1; }
function make(bool $ok): false|Box { return $ok ? new Box() : false; }
function use_box(Box $b): int { return $b->n; }
try {
    echo use_box(make(false)), "\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', explode(', called in', $e->getMessage())[0], "\n";
}
echo use_box(make(true)), "\n";

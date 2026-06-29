<?php
declare(strict_types=1);
// declare is tolerated (semantics ignored) — must not break compilation.
function f(int $x): int { return $x + 1; }
echo f(5), "\n";

declare(ticks=1) {
    echo "block\n";
}

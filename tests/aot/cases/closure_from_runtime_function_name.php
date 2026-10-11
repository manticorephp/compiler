<?php
// Closure::fromCallable with a runtime function name returns a closure that crashes
function h2(int $a): int { return $a + 1; }
function mk(string $name): Closure { return Closure::fromCallable($name); }
$c = mk(strrev('2h'));
echo $c(3), "\n";

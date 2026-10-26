<?php
// A named argument after a spread into a variadic function is bound by position instead of by parameter name.
function g(int $a, int $b = 5, bool $boolean = false, ...$rest) { return "$a-$b-" . ($boolean ? 'T' : 'F') . '-' . count($rest); }
$p = [3];
echo g(...$p, boolean: true), "\n";

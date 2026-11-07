<?php
// count() of `$a + $b` over two bare `array` parameters answers garbage.
// issue: #517
function f(array $a, array $b) { return count($a + $b); }
echo f([], ['k' => 'v', 'j' => 'w']), "\n";

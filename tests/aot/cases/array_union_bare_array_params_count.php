<?php
// count() of `$a + $b` over two bare `array` parameters answers garbage.
function f(array $a, array $b) { return count($a + $b); }
echo f([], ['k' => 'v', 'j' => 'w']), "\n";

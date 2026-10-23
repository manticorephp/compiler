<?php
// Calling an erased object value that defines __invoke (`id($o)(3)`) crashes.
class C { function __invoke($a) { return $a * 3; } }
function id($x) { return $x; }
$o = new C;
echo id($o)(3), "\n";

<?php
// ->__invoke() on an erased Closure runs an unrelated class's __invoke when the program defines one.
class C { function __invoke($a) { return $a * 3; } }
function id($x) { return $x; }
$f = id(fn($q) => $q + 100);
echo $f->__invoke(2), "\n";

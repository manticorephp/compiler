<?php
// Calling an object whose __invoke is variadic crashes with SIGSEGV
class InvVar { public function __invoke(...$a) { return count($a); } }
$f = new InvVar;
echo $f(1, 2), "\n";

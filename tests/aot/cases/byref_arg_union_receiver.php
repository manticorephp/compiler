<?php
// Passing an undefined variable by reference to a method on a `Class|false` receiver fails MIR.verify (dangling local)
class A { function m(&$o) { $o = 1; } }
function mk(): A|false { return new A; }
$a = mk();
$a->m($x);
var_dump($x);

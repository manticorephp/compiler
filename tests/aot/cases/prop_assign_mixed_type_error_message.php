<?php
// Assigning a mixed value of the wrong type to a typed property throws a TypeError with a generic message instead of php's
class A { public int $p = 1; }
function f(A $a, mixed $v) { $a->p = $v; }
try { f(new A, 'abc'); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }

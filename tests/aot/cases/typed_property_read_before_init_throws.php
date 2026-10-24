<?php
// Reading a typed property that was never initialised throws Error
class A { public int $x; public string $s; }
$a = new A;
try { var_dump($a->x); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { var_dump($a->s); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }

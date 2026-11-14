<?php
class A { public function __construct() { echo "a\n"; } }
class B { public function __construct() { echo "b\n"; } }
class C { public function __construct() { echo "c\n"; } }
function pick(bool $f): object {
    if ($f) { $class = A::class; } else { $class = B::class; }
    return new $class;
}
pick(true);

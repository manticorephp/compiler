<?php
class A { public function __construct() { echo "a\n"; } }
class B { public function __construct() { echo "b\n"; } }
function viaParam(string $class): object {
    if (strlen($class) > 3) { $class = 'A'; }
    return new $class;
}
viaParam('B');

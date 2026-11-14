<?php
class A { public function __construct() { echo "a\n"; } }
class B { public function __construct() { echo "b\n"; } }
function make(): object {
    $class = 'A';
    require __DIR__ . '/include_class_inc.php';
    return new $class;
}
make();

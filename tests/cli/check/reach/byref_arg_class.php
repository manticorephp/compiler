<?php
class A { public function __construct() { echo "a\n"; } }
class B { public function __construct() { echo "b\n"; } }
function swap(string &$s): void { $s = 'B'; }
function make(): object {
    $class = 'A';
    swap($class);
    return new $class;
}
make();

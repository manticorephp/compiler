<?php
class X { public function __construct() { echo "x\n"; } }
class Y { public function __construct() { echo "y\n"; } }
class Z { public function other(): int { return 1; } }
$c = implode('', ['X']);
new $c();

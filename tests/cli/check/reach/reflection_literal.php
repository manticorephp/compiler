<?php
class A { public function __construct() { echo "a\n"; } }
class B { public function __construct() { echo "b\n"; } }
(new ReflectionClass(A::class))->newInstance();

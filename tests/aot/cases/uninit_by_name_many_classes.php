<?php
// unserialize() and newInstanceWithoutConstructor() find each of many classes by name, and an unknown name falls through to __PHP_Incomplete_Class / null
class K0 { public int $v = 0; public function __construct() { $this->v = -1; } }
class K1 { public int $v = 1; public function __construct() { $this->v = -1; } }
class K2 { public int $v = 2; public function __construct() { $this->v = -1; } }
class K3 { public int $v = 3; public function __construct() { $this->v = -1; } }
class K4 { public int $v = 4; public function __construct() { $this->v = -1; } }
class K5 { public int $v = 5; public function __construct() { $this->v = -1; } }
class K6 { public int $v = 6; public function __construct() { $this->v = -1; } }
class K7 { public int $v = 7; public function __construct() { $this->v = -1; } }
class K8 { public int $v = 8; public function __construct() { $this->v = -1; } }
class K9 { public int $v = 9; public function __construct() { $this->v = -1; } }
class K10 { public int $v = 10; public function __construct() { $this->v = -1; } }
class K11 { public int $v = 11; public function __construct() { $this->v = -1; } }
class K12 { public int $v = 12; public function __construct() { $this->v = -1; } }
for ($i = 12; $i >= 0; $i -= 3) {
    $o = unserialize(serialize(new ("K$i")()));
    echo get_class($o), ' ', $o->v, "\n";
    $r = (new ReflectionClass("K$i"))->newInstanceWithoutConstructor();
    echo get_class($r), ' ', $r->v, "\n";
}
$u = unserialize('O:7:"Missing":1:{s:1:"a";i:1;}');
echo get_class($u), "\n";
$d = unserialize(serialize(new K5()), ['allowed_classes' => ['K4']]);
echo get_class($d), "\n";

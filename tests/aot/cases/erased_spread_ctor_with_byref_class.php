<?php
// `$o->__construct(...$pack)` on an erased receiver stays correct when some class in the program takes a by-ref constructor parameter:
// that one class no longer keeps every such call inline (PDOStatement::makeObject spliced thousands of arms and a 419 KiB -O0 frame).
class P0 { public $v = 0; public function __construct(int $a = 0) { $this->v = $a; } }
class P1 { public $v = 0; public function __construct(int $a = 0) { $this->v = $a + 1; } }
class P2 { public $v = 0; public function __construct(int $a = 0) { $this->v = $a + 2; } }
class P3 { public $v = 0; public function __construct(int $a = 0) { $this->v = $a + 3; } }
class P4 { public $v = 0; public function __construct(int $a = 0) { $this->v = $a + 4; } }
class P5 { public $v = 0; public function __construct(int $a = 0) { $this->v = $a + 5; } }
class P6 { public $v = 0; public function __construct(int $a = 0) { $this->v = $a + 6; } }
class P7 { public $v = 0; public function __construct(int $a = 0) { $this->v = $a + 7; } }
class P8 { public $v = 0; public function __construct(int $a = 0) { $this->v = $a + 8; } }
class R { public $v = 0; public function __construct(array &$x = []) { $x[] = 1; $this->v = count($x); } }

function mk(string $c, array $a) { $o = new $c(); $o->__construct(...$a); return $o; }

echo mk("P3", [10])->v, "\n";
echo mk("P8", [])->v, "\n";
echo mk("P0", [4])->v, "\n";
$arr = [5, 6];
echo mk("R", [$arr])->v, " ", count($arr), "\n";

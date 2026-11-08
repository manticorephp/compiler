<?php
// `$erased->__construct(...$pack)` with a typed pack (Monomorphize clones the forwarder per
// pack shape): named keys, positional ints and a variadic. Error paths: #595, #596.
class C0 { public function __construct(public int $a = 0, public string $b = '') {} }
class C1 { public function __construct(public int $a = 0) {} }
class C2 { public function __construct(public string $b = 'x') {} }
class C3 { public function __construct(public int $a = 0, public int $c = 1) {} }
class C4 { public function __construct() {} }
class C5 { public function __construct(public ?int $a = null) {} }
class C6 { public array $rest = []; public function __construct(public int $a = 0, int ...$rest) { $this->rest = $rest; } }
class C7 { public function __construct(public float $a = 0.0) {} }
class C8 { public function __construct(public int $need) {} }

function make(array $arguments, bool|object $tryProxy = true): object {
    if (\is_object($tryProxy)) { $tryProxy->__construct(...$arguments); return $tryProxy; }
    return new C0();
}
function show(object $o): void { echo get_class($o), ' ', json_encode(get_object_vars($o)), "\n"; }

show(make(['a' => 5, 'b' => 'hi'], new C0()));
show(make(['b' => 'only'], new C0()));
show(make([7, 8], new C3()));
show(make([1, 2, 3, 4], new C6()));
show(make(['b' => 'z'], new C2()));
show(make([2.5], new C7()));

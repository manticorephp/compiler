<?php
// A virtual call that omits a defaulted argument and one that passes it share the
// same candidate set and list length; each must keep its own argument.
class Item {}
abstract class Base {
    public function make(array $items = []) { return count($items); }
}
class A extends Base {}
class B extends Base {}
class C extends Base {
    public function make(array $items = []) { return count($items) + 100; }
}
function viaDefault(Base $b) { return $b->make(); }
function viaArg(Base $b, array $items) { return $b->make($items); }
$items = [new Item, new Item, new Item];
foreach ([new A, new B, new C] as $o) {
    echo viaDefault($o), ' ', viaArg($o, $items), "\n";
}

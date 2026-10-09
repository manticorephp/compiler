<?php
// unset() of a nested ArrayAccess offset through an untyped (erased) receiver (`unset($n[1][2])`) crashes the binary.
class Inner implements ArrayAccess {
    public function offsetExists(mixed $o): bool { return true; }
    public function offsetGet(mixed $o): mixed { return 'v'; }
    public function offsetSet(mixed $o, mixed $v): void {}
    public function offsetUnset(mixed $o): void { echo "unset\n"; }
}
class Outer implements ArrayAccess {
    public Inner $in;
    public function __construct() { $this->in = new Inner; }
    public function offsetExists(mixed $o): bool { return true; }
    public function offsetGet(mixed $o): mixed { return $this->in; }
    public function offsetSet(mixed $o, mixed $v): void {}
    public function offsetUnset(mixed $o): void {}
}
function f($n) { unset($n[1][2]); }
f(new Outer);

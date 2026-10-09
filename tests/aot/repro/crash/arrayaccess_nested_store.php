<?php
// A nested store through an ArrayAccess object (`$o[1][2] = 1`, any key) crashes the binary.
class Inner implements ArrayAccess {
    public function offsetExists(mixed $o): bool { return true; }
    public function offsetGet(mixed $o): mixed { return 'v'; }
    public function offsetSet(mixed $o, mixed $v): void { echo "set\n"; }
    public function offsetUnset(mixed $o): void {}
}
class Outer implements ArrayAccess {
    public Inner $in;
    public function __construct() { $this->in = new Inner; }
    public function offsetExists(mixed $o): bool { return true; }
    public function offsetGet(mixed $o): mixed { return $this->in; }
    public function offsetSet(mixed $o, mixed $v): void {}
    public function offsetUnset(mixed $o): void {}
}
$n = new Outer;
$n[1][2] = 1;

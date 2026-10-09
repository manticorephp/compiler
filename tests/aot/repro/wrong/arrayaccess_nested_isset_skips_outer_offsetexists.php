<?php
// isset($o[1][2]) on a nested ArrayAccess skips the outer offsetExists() call that php makes first.
class Inner implements ArrayAccess {
    public function offsetExists(mixed $o): bool { echo "Inner::exists\n"; return true; }
    public function offsetGet(mixed $o): mixed { return 'v'; }
    public function offsetSet(mixed $o, mixed $v): void {}
    public function offsetUnset(mixed $o): void {}
}
class Outer implements ArrayAccess {
    public Inner $in;
    public function __construct() { $this->in = new Inner; }
    public function offsetExists(mixed $o): bool { echo "Outer::exists\n"; return true; }
    public function offsetGet(mixed $o): mixed { echo "Outer::get\n"; return $this->in; }
    public function offsetSet(mixed $o, mixed $v): void {}
    public function offsetUnset(mixed $o): void {}
}
$n = new Outer;
var_dump(isset($n[1][2]));

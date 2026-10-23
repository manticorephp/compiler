<?php
// The value of `$obj[$k] = $v` used as an expression is 0/garbage instead of $v for an ArrayAccess object
class Box implements ArrayAccess {
    private array $d = [];
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->d[$o]; }
    public function offsetSet(mixed $o, mixed $v): void { $this->d[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
}
$b = new Box();
var_dump($b[0] = 2.5);
var_dump(($b[1] = 3) + 1);
$s = new SplFixedArray(2);
var_dump($s[0] = 2.5);

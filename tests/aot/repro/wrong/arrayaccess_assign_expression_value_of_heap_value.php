<?php
// The value of `$o[$k] = <string built at run time>` as an expression is garbage; php yields the assigned string
// issue: #420
class Box implements ArrayAccess {
    private array $d = [];
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->d[$o]; }
    public function offsetSet(mixed $o, mixed $v): void { $this->d[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
}
function mk(string $s): string { return "a" . $s; }
$b = new Box();
$x = $b[0] = mk("z");
var_dump($x);
$y = $b[1] = "k" . $x;
var_dump($y);

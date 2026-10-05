<?php
// `$o[$i]` on an ArrayAccess object returned through a `mixed` function keeps an extra reference: the element's destructor runs at shutdown
// issue: #100
class Tok { public function __destruct() { echo "tok\n"; } }
class Box implements ArrayAccess {
    /** @var array<int, mixed> */
    private array $d = [];
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->d[$o]; }
    public function offsetSet(mixed $o, mixed $v): void { $this->d[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
}
function first(Box $b): mixed { return $b[0]; }
$b = new Box(); $b[0] = new Tok();
$x = first($b);
unset($x);
unset($b);
echo "end\n";

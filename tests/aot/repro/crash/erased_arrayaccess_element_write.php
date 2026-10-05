<?php
// An element write `$m[0] = 9` on a mixed value holding an ArrayAccess object crashes (SIGBUS) instead of calling offsetSet
// issue: #95
final class A implements ArrayAccess {
    /** @var array<int, int> */
    private array $d = [0, 0, 0];
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->d[$o]; }
    public function offsetSet(mixed $o, mixed $v): void { $this->d[$o] = (int)$v; }
    public function offsetUnset(mixed $o): void { $this->d[$o] = 0; }
}
function pick(): mixed { return new A(); }
$r = pick();
$r[0] = 9;
echo $r[0], "\n";

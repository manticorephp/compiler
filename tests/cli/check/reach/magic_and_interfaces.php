<?php
class M implements IteratorAggregate, ArrayAccess, JsonSerializable
{
    public function __construct(private array $d = [1, 2]) {}
    public function __toString(): string { return 'm'; }
    public function getIterator(): Iterator { return new ArrayIterator($this->d); }
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->d[$o]; }
    public function offsetSet(mixed $o, mixed $v): void { $this->d[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
    public function jsonSerialize(): mixed { return $this->d; }
    public function unused(): int { return 0; }
}
$m = new M();
echo count(iterator_to_array($m)), "\n";

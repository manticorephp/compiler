<?php
class Bag implements ArrayAccess {
    public function __construct(private array $d) {}
    public function offsetGet(mixed $o): mixed { return $this->d[$o] ?? null; }
    public function offsetExists(mixed $o): bool { return isset($this->d[$o]); }
    public function offsetSet(mixed $o, mixed $v): void { $this->d[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->d[$o]); }
}
function byLit(mixed $x): mixed { return $x['name']; }
var_dump(byLit(['name' => 'ann']));
var_dump(byLit(new Bag(['name' => 'bob'])));
var_dump(byLit(new Bag(['name' => 'cy', 'n' => 1])));
function takesMixedElems(array $a): mixed { return $a['k']; }
var_dump(takesMixedElems(['k' => 'str']));
var_dump(takesMixedElems(['k' => 7]));
var_dump(takesMixedElems(['k' => 1.5]));

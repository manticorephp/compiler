<?php
// An inline `@var T[]` over a mixed value does not make it an array: php never checks a docblock.
class RC implements \IteratorAggregate, \Countable { public array $r = []; public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->r); } public function count(): int { return count($this->r); } }
abstract class L { abstract public function load(mixed $res): mixed; public function import(mixed $res): mixed { return $this->load($res); } }
class A extends L { public function load(mixed $res): mixed { $c = new RC(); $c->r[] = $res; return $c; } }
class Y {
    public function __construct(private L $l) {}
    public function parse(): int {
        /** @var RC[] $imported */
        $imported = $this->l->import('x') ?: [];
        if (!\is_array($imported)) { $imported = [$imported]; }
        $n = 0;
        foreach ($imported as $sub) { $n += count($sub); }
        return $n;
    }
}
echo (new Y(new A()))->parse(), "\n";
function ids(mixed $v): mixed { return $v; }
var_dump((bool)ids(new RC()), ids(new RC()) ?: 'empty');

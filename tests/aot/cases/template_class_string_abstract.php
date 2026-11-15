<?php
// An abstract class's abstract `@template T` method binds `class-string<T>` for an abstract-typed receiver and an undocumented override.
abstract class Repo {
    /**
     * @template T of object
     * @param class-string<T> $cls
     * @return T|null
     */
    abstract public function find(string $cls): ?object;
    public function first(): ?Item { return $this->find(Item::class); }
}
class Item { public function __construct(public int $id = 1) {} }
class MemRepo extends Repo {
    public bool $empty = false;
    public function find(string $cls): ?object { return $this->empty ? null : new $cls(42); }
}
function load(Repo $r): string { $i = $r->find(Item::class); return $i === null ? 'none' : 'item ' . $i->id; }
$r = new MemRepo();
echo load($r), "\n";
echo $r->find(Item::class)->id, "\n";
$r->empty = true;
echo load($r), "\n";
var_dump($r->first());

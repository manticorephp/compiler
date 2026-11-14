<?php
// A non-literal class-string argument keeps the untyped call result (no binding, no guard).
class A { public function v(): string { return 'a'; } }
class B { public function v(): string { return 'b'; } }
class Box {
    /** @var array<string, object> */
    public array $s = [];
    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object { return $this->s[$id]; }
}
$b = new Box();
$b->s['A'] = new A();
$b->s['B'] = new A();
foreach (['A', 'B'] as $id) {
    echo $id, " => ", $b->get($id)->v(), "\n";
}
$k = 'B';
echo get_class($b->get($k)), "\n";

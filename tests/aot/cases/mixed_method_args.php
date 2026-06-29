<?php
// A `mixed` (or union) method / static / constructor parameter must NaN-box its
// argument so the callee reads the runtime tag — like the free-function path.
// Previously a method's `mixed $x` received a raw array/string → mis-read.
class Box {
    private mixed $v;
    public function __construct(mixed $init) { $this->v = $init; }
    public function set(mixed $v): void { $this->v = $v; }
    public function get(): mixed { return $this->v; }
    public static function wrap(mixed $x): mixed { return $x; }
}
$b = new Box(["a", "b", "c"]);
$arr = $b->get();
echo $arr[0], $arr[1], $arr[2], "\n";
$b->set("hello"); echo $b->get(), "\n";
$b->set(42); echo $b->get(), "\n";
$b->set([10, 20]); $x = $b->get(); echo $x[1], "\n";
echo Box::wrap("static"), "\n";
echo Box::wrap(7), "\n";

// A cell-backed list works for every element type now (the basis for a generic
// ArrayIterator): storing an array in a `mixed` field + indexing round-trips.
class ListBox implements Iterator {
    private int $i = 0;
    public function __construct(private mixed $d) {}
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->d); }
    public function current(): mixed { return $this->d[$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i = $this->i + 1; }
}
foreach (new ListBox(["foo", "bar", "baz"]) as $k => $v) { echo $k, ":", $v, "\n"; }

<?php
// An UNTYPED property receiver (`public $defn;`) reads back as UNKNOWN, not
// CELL — two things followed from that, and both were wrong:
//   * the call site looked its parameter tables up under an EMPTY class key,
//     so it coerced nothing and handed the `string|int` CELL param a RAW int;
//   * the class_id load dereferenced the OBJECT tag instead of the pointer.
// symfony's ArgvInput holds its InputDefinition exactly this way, and
// `hasArgument($c - 1)` sent -1 down the string arm of isset().
class Defn {
    private array $args = [];
    public function add(string $n): void { $this->args[$n] = $n; }
    public function has(string|int $name): bool
    {
        $a = \is_int($name) ? \array_values($this->args) : $this->args;
        return isset($a[$name]);
    }
}
class Other {
    public function has(string|int $name): bool { return false; }
}
class Holder {
    public $defn;
    public function __construct($d) { $this->defn = $d; }
    public function probe(int $c): void
    {
        var_dump($this->defn->has($c));
        var_dump($this->defn->has($c - 1));
        var_dump($this->defn->has('cmd'));
    }
}
$d = new Defn();
$d->add('cmd');
$h = new Holder($d);
$h->probe(0);
$o = new Holder(new Other());
$o->probe(0);

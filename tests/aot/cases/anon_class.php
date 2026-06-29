<?php
interface Greeter { public function greet(): string; }
$o = new class implements Greeter {
    public int $n = 42;
    public function greet(): string { return "hi " . $this->n; }
};
echo $o->greet(), "\n";
echo $o->n, "\n";
var_dump($o instanceof Greeter);

function make(int $base): object {
    return new class($base) {
        public function __construct(public int $b) {}
        public function add(int $x): int { return $this->b + $x; }
    };
}
echo make(10)->add(5), "\n";

class Base { public function tag(): string { return "base"; } }
$x = new class extends Base {
    const LIMIT = 5;
    public function tag(): string { return "anon:" . parent::tag() . ":" . self::LIMIT; }
};
echo $x->tag(), "\n";

$a = new class { public function f() { return "A"; } };
$b = new class { public function f() { return "B"; } };
echo $a->f(), $b->f(), "\n";

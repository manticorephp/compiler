<?php
// `...$d` where $d is a foreach value of a bare-array (erased) property: a NaN-boxed cell, not a raw pointer.
class Bag {
    protected array $dep = [];
    public function deprecate(string $name, string $package, string $version, string $message = 'm %s'): void {
        $this->dep[$name] = [$package, $version, $message, $name];
    }
    public static function show(string $a, string $b): void { echo "static $a $b\n"; }
    public function allDeprecated(): array { return $this->dep; }
}
class Frozen extends Bag {
    public function deprecate(string $name, string $package, string $version, string $message = 'm %s'): void { throw new Exception('frozen'); }
}
class Pair {
    public function __construct(public string $a, public string $b) {}
}
function show3(string $a, string $b, string $c = 'dflt'): void { echo "fn $a $b $c\n"; }
function merge(Bag $a, Bag $b): void {
    foreach ($b->allDeprecated() as $name => $deprecated) {
        $a->deprecate($name, ...$deprecated);
        Bag::show(...$deprecated);
        show3(...$deprecated);
        $p = new Pair(...$deprecated);
        echo "pair $p->a $p->b\n";
    }
}
$a = new Bag; $b = new Bag;
$b->deprecate('p', 'pk', '1.0');
$b->deprecate('q', 'qk', '2.0', 'custom');
merge($a, $b);
print_r($a->allDeprecated());

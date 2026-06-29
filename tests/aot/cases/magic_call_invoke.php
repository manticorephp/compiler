<?php
// Method overloading: an unresolved instance method → __call($name, $args);
// an unresolved static method → __callStatic; calling an object → __invoke.
class Proxy {
    public function __call(string $name, array $args): string {
        $s = $name . "(";
        $first = true;
        foreach ($args as $a) {
            if (!$first) { $s .= ","; }
            $s .= $a;
            $first = false;
        }
        return $s . ")";
    }
    public static function __callStatic(string $name, array $args): string {
        return "static::" . $name . "/" . count($args);
    }
}

$p = new Proxy();
echo $p->foo(1, 2, 3), "\n";
echo $p->bar("x"), "\n";
echo $p->noargs(), "\n";
echo Proxy::create(1, 2), "\n";
echo Proxy::find(), "\n";

class Adder {
    public function __construct(private int $base) {}
    public function __invoke(int $x): int { return $this->base + $x; }
}
$add10 = new Adder(10);
echo $add10(5), " ", $add10(100), "\n";

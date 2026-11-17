<?php
// One erased call site meets, in turn, a class that declares the method, a proxy that only has
// __call, and a class with both: the per-site verdict cache must answer per class, every time.
class Plain { public function add(int $x): string { return 'plain:' . $x; } }
class Proxy { /** @param array<int,mixed> $a */ public function __call(string $m, array $a): mixed { return 'proxy:' . $m . ':' . (string)$a[0]; } }
class Both {
    public function add(int $x): string { return 'both:' . $x; }
    /** @param array<int,mixed> $a */ public function __call(string $m, array $a): mixed { return 'both-magic:' . $m; }
}
class Child extends Proxy {}
function erase(mixed $o): mixed { return $o; }
$objs = [new Plain(), new Proxy(), new Both(), new Child(), new Plain(), new Both(), new Proxy()];
for ($round = 0; $round < 2; $round++) {
    foreach ($objs as $i => $o) {
        $e = erase($o);
        echo $e->add($i), ' ', $e->add($i + 10), "\n";
    }
}

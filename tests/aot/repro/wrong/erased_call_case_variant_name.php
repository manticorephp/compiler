<?php
// An erased call spelled in a different case than the declaration misses the method when another class has __call.
class Plain { public function add(int $x): string { return 'plain:' . $x; } }
class Both {
    public function ADD(int $x): string { return 'both:' . $x; }
    /** @param array<int,mixed> $a */ public function __call(string $m, array $a): mixed { return 'magic:' . $m; }
}
function erase(mixed $o): mixed { return $o; }
foreach ([new Plain(), new Both()] as $o) {
    $e = erase($o);
    echo $e->add(1), ' ', $e->Add(2), "\n";
}

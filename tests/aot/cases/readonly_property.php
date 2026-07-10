<?php
class Point {
    public function __construct(
        public readonly int $x,
        public readonly int $y,
    ) {}
}

class Named {
    public readonly string $name;
    public function __construct(string $n) { $this->name = $n; }
}

$p = new Point(3, 4);
echo $p->x, ",", $p->y, "\n";

try {
    $p->x = 10;
} catch (\Error $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
echo $p->x, "\n";

$n = new Named("alice");
echo $n->name, "\n";
try {
    $n->name = "bob";
} catch (\Error $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
echo $n->name, "\n";

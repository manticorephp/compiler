<?php
// A value typed as an interface (return type, param, property) resolves method
// calls — including the return type — via the implementing class at runtime.
interface Shape {
    public function area(): int;
    public function name(): string;
}
class Square implements Shape {
    public function __construct(private int $side) {}
    public function area(): int { return $this->side * $this->side; }
    public function name(): string { return "square"; }
}
class Rect implements Shape {
    public function __construct(private int $w, private int $h) {}
    public function area(): int { return $this->w * $this->h; }
    public function name(): string { return "rect"; }
}

function make(bool $sq): Shape {
    return $sq ? new Square(4) : new Rect(2, 3);
}

$a = make(true);
$b = make(false);
echo $a->name(), "=", $a->area(), "\n";
echo $b->name(), "=", $b->area(), "\n";

/** @param Shape[] $shapes */
function total(array $shapes): int {
    $sum = 0;
    foreach ($shapes as $s) { $sum += $s->area(); }
    return $sum;
}
echo total([new Square(2), new Rect(3, 4), new Square(5)]), "\n";

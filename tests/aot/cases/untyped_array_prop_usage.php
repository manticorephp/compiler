<?php
// A bare-`array` property with NO @var / list default recovers its element type
// from how the class's methods push into it (usage inference), so element reads
// stay typed (object later-fields, methods, strings) instead of erased.
class Point {
    public function __construct(public int $x, public int $y) {}
    public function sum(): int { return $this->x + $this->y; }
}
class Scene {
    public array $points = [];
    public function add(Point $p): void { $this->points[] = $p; }
}
$s = new Scene();
$s->add(new Point(1, 2));
$s->add(new Point(3, 4));
echo $s->points[0]->y, " ", $s->points[1]->x, "\n";   // later field
echo $s->points[0]->sum(), " ", $s->points[1]->sum(), "\n"; // method
$t = 0;
foreach ($s->points as $p) { $t += $p->x; }
echo $t, "\n";

class Log {
    public array $lines = [];
    public function write(string $line): void { $this->lines[] = $line; }
}
$l = new Log();
$l->write("first");
$l->write("second");
echo $l->lines[0], "/", $l->lines[1], "\n";

// new-expression store also infers the element
class Factory {
    public array $made = [];
    public function build(): void { $this->made[] = new Point(9, 9); }
}
$f = new Factory();
$f->build();
echo $f->made[0]->sum(), "\n";

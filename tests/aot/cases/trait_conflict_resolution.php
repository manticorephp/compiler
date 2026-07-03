<?php
// Trait conflict resolution: `use A, B { A::m insteadof B; B::m as bM; }`.
trait Hello { public function greet(): string { return "Hello"; } }
trait Hi    { public function greet(): string { return "Hi"; } }
class Greeter {
    use Hello, Hi {
        Hello::greet insteadof Hi;   // Hello wins
        Hi::greet as sayHi;          // keep Hi's under a new name
    }
}
$g = new Greeter();
echo $g->greet(), " / ", $g->sayHi(), "\n";

trait Counter {
    public int $n = 0;
    public function inc(): void { $this->n++; }
    public function get(): int { return $this->n; }
}
class Box {
    use Counter { get as value; }    // plain alias
}
$b = new Box();
$b->inc(); $b->inc();
echo $b->get(), " ", $b->value(), "\n";

// multiple losers on one insteadof
trait A { public function tag(): string { return "A"; } }
trait B { public function tag(): string { return "B"; } }
trait C { public function tag(): string { return "C"; } }
class Pick {
    use A, B, C { A::tag insteadof B, C; }
}
echo (new Pick())->tag(), "\n";

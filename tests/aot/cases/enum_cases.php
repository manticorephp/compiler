<?php
// `Enum::cases()` returns a list of every case (in declaration order). An enum
// value is carried as its ordinal, so cases() is [0..N-1] typed obj<Enum> and
// ->name / ->value / methods dispatch per element.
enum Suit { case Hearts; case Spades; case Diamonds; case Clubs; }
enum Status: string { case Active = 'A'; case Done = 'D'; }
enum Priority: int { case Low = 1; case High = 10; }

echo count(Suit::cases()), " ", count(Status::cases()), " ", count(Priority::cases()), "\n";

foreach (Suit::cases() as $s) { echo $s->name, " "; }
echo "\n";

foreach (Status::cases() as $s) { echo $s->name, "=", $s->value, " "; }
echo "\n";

$sum = 0;
foreach (Priority::cases() as $p) { $sum += $p->value; }
echo $sum, "\n";

$names = array_map(fn($c) => $c->name, Suit::cases());
echo implode(",", $names), "\n";

// A backed enum with an interface + method still exposes cases().
interface Labelled { public function label(): string; }
enum Dir: int implements Labelled {
    case N = 0; case E = 1; case S = 2; case W = 3;
    public function label(): string { return $this->name . "(" . $this->value . ")"; }
}
foreach (Dir::cases() as $d) { echo $d->label(), " "; }
echo "\n";

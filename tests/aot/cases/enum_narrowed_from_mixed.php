<?php
enum Suit: string { case Hearts = 'h'; case Spades = 's'; public function label(): string { return ucfirst($this->value); } }
enum Dir { case Up; case Down; }
function f(mixed $v): string {
    if ($v instanceof Suit) { return 'n=' . $v->name . ' v=' . $v->value . ' l=' . $v->label(); }
    if ($v instanceof Dir) { return 'dir ' . $v->name . ($v === Dir::Down ? ' down' : ' not-down'); }
    return 'other';
}
function g(mixed $v): Suit { if ($v instanceof Suit) { return $v; } return Suit::Hearts; }
function h(mixed $v): mixed { if ($v instanceof Suit) { $w = $v; return [$w, $v->value]; } return null; }
echo f(Suit::Spades), "\n", f(Suit::Hearts), "\n", f(Dir::Down), "\n", f(Dir::Up), "\n", f(5), "\n";
echo g(Suit::Spades)->name, ' ', g('x')->name, "\n";
var_dump(h(Suit::Spades));
$list = [Suit::Hearts, 1, Dir::Up, Suit::Spades];
foreach ($list as $item) { echo f($item), '|'; }
echo "\n";
$typed = Suit::Spades;
echo $typed->name, ' ', match ($typed) { Suit::Spades => 'sp', Suit::Hearts => 'he' }, "\n";

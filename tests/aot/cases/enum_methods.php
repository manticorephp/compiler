<?php
// Enum instance methods: $this->name / $this->value, match($this), interface
// impl, and enum constants (self::CONST + Enum::CONST).
interface HasLabel { public function label(): string; }
enum Suit: string implements HasLabel {
    case Hearts = "h";
    case Spades = "s";
    const DECK = 52;
    public function label(): string { return strtoupper($this->value); }
    public function color(): string { return match ($this) { Suit::Hearts => "red", Suit::Spades => "black" }; }
    public function isRed(): bool { return $this === Suit::Hearts; }
    public function deckSize(): int { return self::DECK; }
}
$h = Suit::Hearts;
echo $h->name, " ", $h->label(), " ", $h->color(), " ", $h->isRed() ? "R" : "B", "\n";
echo Suit::Spades->label(), " ", Suit::Spades->color(), " ", Suit::Spades->deckSize(), "\n";
echo Suit::DECK, "\n";

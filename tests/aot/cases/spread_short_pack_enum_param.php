<?php
// A spread pack too short for a required enum parameter raises ArgumentCountError (no stray read).
enum Suit { case Hearts; case Spades; }

final class Deck
{
    public function deal(int $n, Suit $s): string { return $n . ':' . $s->name; }
}

function show(int $n, Suit $s, string $tag = 'x'): string { return $n . '/' . $s->name . '/' . $tag; }

/** @return mixed[] */
function argpack(mixed ...$v): array { return $v; }

$d = new Deck();
echo $d->deal(...argpack(1, Suit::Spades)), "\n";
echo show(...argpack(2, Suit::Hearts)), "\n";
foreach ([fn () => $d->deal(...argpack(3)), fn () => show(...argpack(4))] as $f) {
    try {
        echo $f(), "\n";
    } catch (ArgumentCountError $e) {
        echo get_class($e), "\n";
    }
}
$m = 'deal';
try {
    echo $d->$m(...argpack(5)), "\n";
} catch (ArgumentCountError $e) {
    echo get_class($e), "\n";
}
echo "done\n";

<?php
enum Color { case Red; case Green; case Blue; }
function describe(Color $c): string {
    return match ($c) {
        Color::Red => "warm",
        Color::Green => "fresh",
        Color::Blue => "cool",
    };
}
echo describe(Color::Red), ",", describe(Color::Green), ",", describe(Color::Blue);

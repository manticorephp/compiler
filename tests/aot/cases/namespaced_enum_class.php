<?php
// Namespaced classes + enums (braced namespace) — generic support, distinct from
// the Io\Poll prelude (a neutral namespace avoids pulling / colliding with it).
namespace Demo\Widget {
    interface Shape {}
    enum Color { case Red; case Green; }
    enum Kind: int { case A = 1; case B = 2; }
    final class Box {
        public function __construct(public Color $color = Color::Red) {}
        public function getColor(): Color { return $this->color; }
    }
}
namespace {
    $b = new \Demo\Widget\Box();
    echo $b->getColor()->name, "\n";
    echo \Demo\Widget\Color::Green->name, "\n";
    echo \Demo\Widget\Kind::B->name, "=", \Demo\Widget\Kind::B->value, "\n";
    var_dump($b instanceof \Demo\Widget\Box);
    var_dump($b->getColor() === \Demo\Widget\Color::Red);
}

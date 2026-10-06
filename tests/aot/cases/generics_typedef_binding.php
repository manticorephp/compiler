<?php
// superset: no php oracle for the erased #[TypeDef] forms — expected output is written by hand
namespace App\Model {

    #[\TypeDef(repr: 'u16')]
    final class Kind
    {
        public function __construct(public readonly int $value) {}
        public function isComment(): bool { return $this->value === 7 || $this->value === 8; }
    }

    /** @template T */
    final class Box
    {
        /** @var T */
        private $v;
        /** @param T $v */
        public function __construct($v) { $this->v = $v; }
        /** @return T */
        public function get() { return $this->v; }
        /** @return T */
        public function peek(): mixed { return $this->v; }
    }

    /** @template T */
    final class Shelf implements \ArrayAccess
    {
        /** @var array<int, int> */
        private array $raw = [];
        public function offsetExists(mixed $k): bool { return isset($this->raw[$k]); }
        /** @return T */
        public function offsetGet(mixed $k): int { return $this->raw[$k]; }
        public function offsetSet(mixed $k, mixed $v): void { $this->raw[$k] = $v; }
        public function offsetUnset(mixed $k): void { unset($this->raw[$k]); }
    }
}

namespace App {

    use App\Model\Box;
    use App\Model\Kind;
    use App\Model\Shelf;

    final class Holder
    {
        /** @var Shelf<Kind> */
        public Shelf $shelf;
        public function __construct() { $this->shelf = new Shelf(); }
        public function first(): bool { return $this->shelf[0]->isComment(); }
    }

    /** @param Shelf<Kind> $s */
    function second(Shelf $s): bool { return $s[1]->isComment(); }

    // a short class name behind `use`, bound to a TypeDef
    /** @var Box<Kind> $b */
    $b = new Box(new Kind(7));
    echo $b->get()->isComment() ? "y" : "n", $b->peek()->isComment() ? "y" : "n", " ", $b->get()->value, "\n";
    $k = $b->get();
    echo $k->isComment() ? "y" : "n", "\n";

    /** @var Box<float> $f */
    $f = new Box(1.5);
    echo $f->get() + $f->peek(), "\n";

    $h = new Holder();
    $h->shelf[0] = 7;
    $h->shelf[1] = 3;
    echo $h->first() ? "y" : "n", second($h->shelf) ? "y" : "n", " ", $h->shelf[0]->value + 1, "\n";
}

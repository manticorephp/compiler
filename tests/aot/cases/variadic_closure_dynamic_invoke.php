<?php
// A variadic closure reached through an erased slot gets its pack built from the trailing arguments.
class L { public function on(object $e, string $n, object $d): void { echo get_class($e), " $n ", get_class($d), "\n"; } }
class E {}
class D {
    /** @var array<int, mixed> */
    public array $ls = [];
    public function add(mixed $listener): void {
        $this->ls[] = static function (...$args) use (&$listener) { ($listener)(...$args); };
    }
    public function fire(object $e, string $n): void { foreach ($this->ls as $l) { $l($e, $n, $this); } }
}
$d = new D(); $d->add([new L(), 'on']); $d->fire(new E(), 'evt');
function ids(mixed $v): mixed { return $v; }
$v = ids(static fn (string $a, string ...$rest) => $a . ':' . implode(',', $rest));
echo $v('x', 'y', 'z'), ' ', $v('only'), "\n";

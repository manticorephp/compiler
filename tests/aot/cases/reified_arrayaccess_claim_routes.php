<?php
// `$b[...]` and `$b(...)` build their offsetSet / __invoke calls at emit time;
// they obey the same reified-claim rule as a written method call: an arg that
// does not fit the binding's claim runs the origin's erased method (#133).
// offsetGet carries no `@return T`: a return claim is #137.

/** @template T */
final class Bag implements ArrayAccess
{
    /** @var array<int|string, mixed> */
    private array $items = [];

    public function offsetExists(mixed $offset): bool { return isset($this->items[$offset]); }

    public function offsetGet(mixed $offset): mixed { return $this->items[$offset]; }

    /** @param T $value */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) { $this->items[] = $value; } else { $this->items[$offset] = $value; }
    }

    public function offsetUnset(mixed $offset): void { unset($this->items[$offset]); }

    /** @param T $value */
    public function __invoke(mixed $value): mixed { return $value; }
}

/** @var Bag<string> $b */
$b = new Bag();
$b['k'] = 5;
var_dump($b['k']);
$b['f'] = 2.5;
var_dump($b['f']);
$b['s'] = "fits";
var_dump($b['s']);
$b[] = [1, 2];
var_dump($b[0]);
var_dump($b(7));
var_dump($b("str"));
var_dump(isset($b['k']));
unset($b['k']);
var_dump(isset($b['k']));

<?php

// Docblock generics: ONE compiled Box serves every instantiation. Inside the
// shared body `T` is erased and travels as a tagged cell; the CALL SITE knows
// the binding (`Box<float>`) and refines the result from it.
//
// Before generics the erased `@return T` came back typed `unknown` (a raw i64),
// so `.` printed a pointer's digits and `+` on floats printed a double's bit
// pattern — silently wrong output.

/** @template T */
final class Box
{
    /** @var T[] */
    private array $items = [];

    /** @param T $x */
    public function add($x): void { $this->items[] = $x; }

    /** @return T */
    public function get(int $i) { return $this->items[$i]; }

    public function count(): int { return \count($this->items); }
}

final class Tag
{
    public function __construct(public readonly string $name, public readonly int $n) {}
}

/** @var Box<int> $ints */
$ints = new Box();
$ints->add(10);
$ints->add(32);

/** @var Box<string> $strs */
$strs = new Box();
$strs->add('ab');
$strs->add('cd');

/** @var Box<float> $floats */
$floats = new Box();
$floats->add(1.5);
$floats->add(2.25);

/** @var Box<Tag> $tags */
$tags = new Box();
$tags->add(new Tag('alpha', 1));
$tags->add(new Tag('beta', 2));

echo $ints->get(0) + $ints->get(1), "\n";
echo $strs->get(0) . $strs->get(1), "\n";
echo strlen($strs->get(1)), "\n";
echo $floats->get(0) + $floats->get(1), "\n";
echo $floats->get(1) * 2.0, "\n";
echo $tags->get(0)->name, $tags->get(0)->n, "\n";
echo $tags->get(1)->name, $tags->get(1)->n, "\n";
echo $ints->count(), $strs->count(), $floats->count(), $tags->count(), "\n";

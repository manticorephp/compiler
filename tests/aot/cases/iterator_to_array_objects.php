<?php

/*
 * iterator_to_array() / iterator_count() over a real Iterator and a real
 * IteratorAggregate. Every non-array argument used to take the Generator arm —
 * behind a `\Generator`-typed parameter — so an object read the generator frame
 * layout off its own header and the process died. A Generator cannot be
 * recognised directly (no class descriptor), so the test is inverted: anything
 * answering \Iterator / \IteratorAggregate is handled as such, and a Generator
 * keeps the fallthrough.
 */

class It implements Iterator
{
    private int $i = 0;
    /** @var array<int,string> */
    private array $d = ['x', 'y', 'z'];
    public function current(): mixed { return $this->d[$this->i]; }
    public function key(): mixed { return $this->i * 10; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->d); }
}

class Agg implements IteratorAggregate
{
    public function getIterator(): Iterator { return new It(); }
}

function gen() { yield 'a' => 1; yield 'b' => 2; }

print_r(iterator_to_array(gen()));
print_r(iterator_to_array(gen(), false));
print_r(iterator_to_array([1, 2]));
print_r(iterator_to_array([1, 2], false));
print_r(iterator_to_array(new It()));
print_r(iterator_to_array(new It(), false));
print_r(iterator_to_array(new Agg()));
print_r(iterator_to_array(new ArrayIterator(['k' => 'v'])));
echo iterator_count(new It()), ' ', iterator_count(new Agg()), ' ',
     iterator_count(gen()), ' ', iterator_count([1, 2, 3]), "\n";

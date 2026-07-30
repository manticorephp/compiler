<?php
// `instanceof` over an ERASED operand used to inttoptr the carrier whatever it
// held and read a class id out of it. An erased slot holds a boxed cell, a raw
// object pointer, a raw array or a raw string — so classify at runtime:
// above the NaN header the tag decides; below it, only the allocator's
// RC_TAG_MAGIC at ptr-8 marks an object (an array carries ARRAY_TAG_MAGIC there
// and a string its refcount).
// symfony's Table walks rows that are sometimes a TableSeparator and sometimes
// an array of header STRINGS through the same `instanceof`.
class Sep {}
class Cell { public function __construct(public string $v) {} }

function rawObjs(): array { return [new Sep(), new Cell('x')]; }
function rawStrs(): array { return ['alpha', 'beta']; }
function rawArrs(): array { return [['a'], ['b']]; }

function describe(array $items): void
{
    foreach ($items as $it) {
        if ($it instanceof Sep) { echo "SEP\n"; continue; }
        if ($it instanceof Cell) { echo "CELL\n"; continue; }
        echo \is_array($it) ? "ARR\n" : "OTHER\n";
    }
}
describe(rawObjs());
describe(rawStrs());
describe(rawArrs());

class Holder {
    private array $items = [];
    public function add(mixed $v): void { $this->items[] = $v; }
    public function walk(): void { describe($this->items); }
}
$h = new Holder();
$h->add(new Sep());
$h->add('str');
$h->add(new Cell('y'));
$h->add(7);
$h->walk();

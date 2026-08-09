<?php
// @epic: ref-cells
//
// A PROPERTY and a STATIC PROPERTY as the source of a storable reference —
// instalment 2 of docs/design/reference-cells.md.
//
// The properties are CONCRETELY typed on purpose. That is the whole difficulty
// and the reason the corpus witness (symfony's DumpDataCollector, which takes
// references to `private int` and `private bool`) is not served by "they are
// already cells, just box them": taking a reference has to be able to change a
// slot's REPRESENTATION, so the promotion retypes the property to a cell for
// its whole lifetime.
//
// Disable seam: MANTICORE_REF_CELLS=0 restores the refusal in lowerArrayLit.

class Reg
{
    public static int $count = 0;
    public static string $name = 'zero';
}

class C
{
    private int $n = 0;
    private bool $f = true;
    private string $s = 'a';
    public array $refs = [];

    public function __construct()
    {
        $this->refs = [&$this->n, &$this->f, &$this->s];
    }

    public function bump(): void { $this->n = $this->n + 1; }
    public function n(): int { return $this->n; }
    public function f(): bool { return $this->f; }
    public function s(): string { return $this->s; }
}

$c = new C();
echo "init: ", $c->refs[0], " ", ($c->refs[1] ? "t" : "f"), " ", $c->refs[2], "\n";

// property -> element
$c->bump();
echo "prop->elem: ", $c->refs[0], "\n";

// element -> property, through the reference
$c->refs[0] = 41;
echo "elem->prop: ", $c->n(), "\n";
$c->refs[1] = false;
echo "bool: ", ($c->f() ? "t" : "f"), "\n";
$c->refs[2] = 'zz';
echo "string: ", $c->s(), "\n";

// the binding survives the store
$c->bump();
echo "still bound: ", $c->refs[0], " ", $c->n(), "\n";

// a second object has its OWN slots, so its own box
$d = new C();
$d->refs[0] = 5;
echo "independent: ", $d->n(), " ", $c->n(), "\n";

// ⛔ A STATIC property as the SOURCE still refuses: its address is available
// but the slot is not retyped to a cell yet, and enabling it without that
// printed a denormal. See the note in lowerArrayLit.

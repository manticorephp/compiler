<?php
// A CONSTRUCTOR honours by-ref params like any other method; emitNewObj did
// not consult the ref mask, so `new Section($n, $this->sections)` against
// `array &$sections` passed the array POINTER. array_unshift then wrote the
// relocated buffer back through it — straight into the empty-array
// singleton's LENGTH field. Every later `= []` copied that as its length, so
// the first append to any such array wrote off the end of the buffer.
// symfony hits it in ConsoleOutput::section(), i.e. on every table render.
class Section {
    /** @var array<int, Section> */
    private array $sections;
    public function __construct(public string $name, array &$sections)
    {
        \array_unshift($sections, $this);
        $this->sections = &$sections;
    }
    public function siblings(): int { return \count($this->sections); }
}
class Out {
    private array $sections = [];
    public function make(string $n): Section { return new Section($n, $this->sections); }
    public function count_(): int { return \count($this->sections); }
}
$o = new Out();
$a = $o->make('a');
echo 'after a: out=', $o->count_(), ' sec=', $a->siblings(), "\n";
$b = $o->make('b');
echo 'after b: out=', $o->count_(), ' sec=', $b->siblings(), "\n";

// The singleton must survive: an unrelated `= []` + append still works.
class Box { private array $rows = []; public function add(array $r): void { $this->rows[] = $r; } public function n(): int { return \count($this->rows); } }
$bx = new Box();
$bx->add(['x', 'y']);
$bx->add(['z']);
echo 'rows=', $bx->n(), "\n";
$e = [];
$e[] = 1;
$e[] = 2;
echo 'e=', \count($e), "\n";

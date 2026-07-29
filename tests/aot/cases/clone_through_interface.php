<?php
// `clone` over an INTERFACE-typed receiver was the IDENTITY — the emitter had
// no ClassDef for the interface and passed the pointer straight through, so the
// "copy" WAS the original and a write through it hit the shared object.
//
// symfony's SymfonyStyle builds its buffered output with
// `new TrimmedBufferOutput(…, false, clone $output->getFormatter())`, then that
// constructor calls setDecorated(false) — on the REAL console formatter. The
// whole application rendered without colour.
//
// The class id decides at runtime now; an implementer this module cannot see
// still passes through, as before.

interface Fmt
{
    public function set(bool $v): void;
    public function get(): bool;
}

class F implements Fmt
{
    private bool $d = false;
    private array $styles = [];

    public function set(bool $v): void { $this->d = $v; }
    public function get(): bool { return $this->d; }
    public function push(string $s): void { $this->styles[] = $s; }
    public function count(): int { return count($this->styles); }
}

class NullFmt implements Fmt
{
    public function set(bool $v): void {}
    public function get(): bool { return false; }
}

class Wrapper implements Fmt
{
    private Fmt $inner;

    public function __construct(Fmt $i) { $this->inner = $i; }
    public function set(bool $v): void { $this->inner->set($v); }
    public function get(): bool { return $this->inner->get(); }
}

function copyOf(Fmt $f): Fmt { return clone $f; }

$a = new F();
$a->set(true);
$a->push('info');
$b = copyOf($a);
$b->set(false);
echo ($a->get() ? 1 : 0), ($b->get() ? 1 : 0), "\n";

// Array properties stay VALUES across the dynamic clone.
$b->push('comment');
echo $a->count(), $b->count(), "\n";

// A second implementer, and a wrapper holding one.
$n = new NullFmt();
echo (copyOf($n)->get() ? 1 : 0), "\n";
$w = new Wrapper($a);
$x = copyOf($w);
echo ($x->get() ? 1 : 0), "\n";

// symfony's exact shape: clone the result of an interface-typed getter.
interface Out { public function getFmt(): Fmt; }

class Cons implements Out
{
    private Fmt $f;

    public function __construct(Fmt $f) { $this->f = $f; }
    public function getFmt(): Fmt { return $this->f; }
}

function wrap(Out $o): Fmt
{
    $copy = clone $o->getFmt();
    $copy->set(false);
    return $copy;
}

$src = new F();
$src->set(true);
echo (wrap(new Cons($src))->get() ? 1 : 0), ($src->get() ? 1 : 0), "\n";

// A CONCRETE receiver keeps the static path.
$d = clone $src;
$d->set(false);
echo ($src->get() ? 1 : 0), ($d->get() ? 1 : 0), "\n";
echo "done\n";

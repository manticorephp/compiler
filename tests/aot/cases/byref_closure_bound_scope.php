<?php

// `fn &()` / `function &()` returning by reference, and the shape symfony's
// MicroKernelTrait::registerContainerConfiguration() writes:
//
//   $instanceof = &\Closure::bind(fn &() => $this->instanceof, $loader, $loader)();
//
// Four things stack there: a by-reference arrow fn, Closure::bind rebinding
// `$this` to an object of a class the closure was NOT written in, an
// immediately-invoked closure, and `= &` binding a local to the returned alias
// so later writes reach the PROPERTY.

class Loader
{
    // ⚠ Two properties BEFORE the interesting one, on purpose. `propertyOffset`
    // falls back to slot 16 when the statically-assumed class declares nothing
    // by that name, and 16 is the FIRST field -- so a class whose target
    // property happens to sit there cannot tell a correct offset from the
    // guess, and the test passes with the fix disabled.
    private string $prefix = 'p';
    private int $seq = 0;

    /** @var array<string,int> */
    private array $instanceof = [];

    public function names(): string { return implode(',', array_keys($this->instanceof)); }
    public function total(): int { $t = 0; foreach ($this->instanceof as $v) { $t = $t + $v; } return $t; }
    public function untouched(): string { return $this->prefix . ':' . $this->seq; }
}

class Kernel
{
    // The arrow fn is LEXICALLY inside Kernel, which has no `instanceof`
    // property at all -- the offset cannot come from the enclosing class.
    public function configure(Loader $l): void
    {
        $instanceof = &\Closure::bind(fn &() => $this->instanceof, $l, $l)();
        $instanceof['A'] = 1;
        $instanceof['B'] = 2;
    }
}

$l = new Loader();
(new Kernel())->configure($l);
echo $l->names(), "\n";      // A,B
echo $l->total(), "\n";      // 3
echo $l->untouched(), "\n";  // p:0 -- the slot-16 guess would have written HERE

// A by-ref closure with no rebinding at all: the alias must still write through.
class Holder
{
    /** @var array<int,string> */
    public array $items = ['first'];

    public function refItems(): \Closure { return function &(): array { return $this->items; }; }
}

$h = new Holder();
$f = $h->refItems();
$ref = &$f();
$ref[] = 'second';
echo implode('|', $h->items), "\n";   // first|second

// ⚠ NOT asserted here: VALUE context on a by-ref return of an ARRAY should
// yield a COPY. It does not, and that is older and broader than this case --
// the NAMED form diverges identically (`function &g(H $h): array { return
// $h->items; }` then `$copy = g($h); $copy[] = 'b';` appends in place and
// leaves the property reading empty). See the by-ref-value-context-no-copy
// probe; asserting it here would tie an unrelated rc/ownership root to the
// closure ABI. Value context on a SCALAR does work, and is asserted below.

// A by-ref arrow fn over a scalar property, bound to its own class.
class Counter
{
    public int $n = 0;
    public function bump(): void
    {
        $r = &\Closure::bind(fn &() => $this->n, $this, $this)();
        $r = $r + 5;
    }
}

$c = new Counter();
$c->bump();
$c->bump();
echo $c->n, "\n";                     // 10

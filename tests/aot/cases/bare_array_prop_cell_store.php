<?php
// A CELL value stored into a bare-`array` property. The hint erases to
// KIND_UNKNOWN, so nothing unboxed the tagged word and the slot ended up
// holding a NaN-boxed array while every reader (the getter's `return
// $this->aliases;`, then the caller's foreach) treats it as a raw pointer —
// symfony's Command::setAliases + Application::add, a hard SIGSEGV.

class Registry
{
    private array $aliases = [];
    private array $seen = [];

    // `iterable` ⇒ the parameter is a cell; the ternary hands one arm the raw
    // cell and the other a freshly built array, so BOTH arms are cell-typed.
    public function setAliases(iterable $aliases): static
    {
        $list = [];
        foreach ($aliases as $alias) {
            $list[] = $alias;
        }
        $this->aliases = \is_array($aliases) ? $aliases : $list;
        return $this;
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function register(): void
    {
        foreach ($this->getAliases() as $alias) {
            $this->seen[$alias] = \strlen($alias);
        }
    }

    public function seen(): array
    {
        return $this->seen;
    }
}

$r = new Registry();
$r->setAliases(['alpha', 'be', 'gamma']);
$r->register();
var_dump($r->getAliases());
var_dump($r->seen());

// A second store must observe the slot in the same repr it was left in.
$r->setAliases(['delta']);
var_dump($r->getAliases());
var_dump(count($r->getAliases()));

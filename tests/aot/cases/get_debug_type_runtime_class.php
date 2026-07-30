<?php
namespace Sym;

class Command
{
    private ?string $name = null;
    public function __construct(?string $name = null)
    {
        if (null !== $name) { $this->setName($name); }
        $this->configure();
    }
    protected function configure(): void {}
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getName(): ?string { return $this->name; }
}

class HelpCommand extends Command
{
    protected function configure(): void { $this->setName('help'); }
}
class ListCommand extends Command
{
    protected function configure(): void { $this->setName('list'); }
}

function add(Command $c): void
{
    if (!$c->getName()) {
        echo "EMPTY NAME for ", \get_debug_type($c), "\n";
        return;
    }
    echo "ok ", $c->getName(), " / debug=", \get_debug_type($c), " / class=", \get_class($c), "\n";
}

foreach ([new HelpCommand(), new ListCommand(), new Command('x')] as $c) { add($c); }

// A KIND_OBJ slot holding null still reports "null", and a leaf class with no
// descendants keeps the folded literal.
final class Leaf { }
function pick(bool $b): ?Leaf { return $b ? new Leaf() : null; }
var_dump(\get_debug_type(pick(true)));
var_dump(\get_debug_type(pick(false)));
var_dump(\gettype(new HelpCommand()));

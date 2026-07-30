<?php
// A class that reaches an interface through ANOTHER interface
// (`interface Wrappable extends Base`) did not count as implementing the base:
// ClassDef carried only the direct `implements` line, and `classImplements` /
// `classIsA` walk exactly that list. `$impl instanceof Base` answered FALSE,
// and the same lookup drives the interface-typed clone dispatch and the catch
// matcher — symfony's OutputFormatter implements
// WrappableOutputFormatterInterface, so `clone $output->getFormatter()` found no
// candidate, cloned nothing, and the whole app rendered without colour.

interface Base { public function get(): bool; }
interface Wrappable extends Base { public function extra(): int; }
interface Deep extends Wrappable {}

class Impl implements Wrappable
{
    private bool $d = false;

    public function get(): bool { return $this->d; }
    public function extra(): int { return 1; }
    public function set(bool $v): void { $this->d = $v; }
}

class DeepImpl implements Deep
{
    public function get(): bool { return true; }
    public function extra(): int { return 2; }
}

class Direct implements Base
{
    public function get(): bool { return false; }
}

$a = new Impl();
$b = new DeepImpl();
$c = new Direct();

echo ($a instanceof Impl ? 1 : 0), ($a instanceof Wrappable ? 1 : 0), ($a instanceof Base ? 1 : 0), "\n";
echo ($b instanceof Deep ? 1 : 0), ($b instanceof Wrappable ? 1 : 0), ($b instanceof Base ? 1 : 0), "\n";
echo ($c instanceof Base ? 1 : 0), ($c instanceof Wrappable ? 1 : 0), "\n";

// A base-interface param accepts every one of them.
function readIt(Base $x): int { return $x->get() ? 1 : 0; }
$a->set(true);
echo readIt($a), readIt($b), readIt($c), "\n";

// clone through the BASE interface reaches the derived implementer.
function copyOf(Base $x): Base { return clone $x; }
$d = copyOf($a);
$d->set(false);
echo ($a->get() ? 1 : 0), ($d->get() ? 1 : 0), "\n";

// (`is_a($var, 'X')` with a VARIABLE subject is a separate, pre-existing gap:
// reflClassName sees no literal class name and folds the answer to false.)
echo (interface_exists('Wrappable') ? 1 : 0), "\n";
echo "done\n";

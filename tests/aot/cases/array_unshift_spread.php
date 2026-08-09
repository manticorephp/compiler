<?php
// `array_unshift($a, $x, ...$pack)` — the pack is a runtime array, so the
// emitter walks it (`__mir_array_unshift_all`) instead of expanding it against
// a fixed arity. The tier-4 blocker's shape:
// symfony/dependency-injection/…/EnvConfigurator.php:50.
$args = [20, 30];

$a = [40];
echo array_unshift($a, 10, ...$args), ": ", implode(",", $a), "\n";

// pack only
$b = [5];
array_unshift($b, ...$args);
echo implode(",", $b), "\n";

// an EMPTY pack must not touch the array (and must not deref a null buffer)
$c = [1];
$empty = [];
echo array_unshift($c, ...$empty), ": ", implode(",", $c), "\n";

// a string pack — every element gains a second owner
$sp = ["b", "c"];
$d = ["d"];
array_unshift($d, "a", ...$sp);
echo implode(",", $d), " pack=", implode(",", $sp), "\n";

// two packs in one call
$e = ["end"];
$p1 = ["a", "b"];
$p2 = ["c"];
array_unshift($e, ...$p1, ...$p2);
echo implode(",", $e), "\n";

class Configurator
{
    /** @var array<int,string> */
    private array $stack = [];

    public function push(string $processor, string ...$args): void
    {
        array_unshift($this->stack, $processor, ...$args);
    }

    public function dump(): string
    {
        return implode(",", $this->stack);
    }
}

$cfg = new Configurator();
$cfg->push("resolve");
$cfg->push("default", "fallback", "value");
echo $cfg->dump(), "\n";

// the pack survives the call intact and can be reused
$f = [];
array_unshift($f, ...$sp);
array_unshift($f, ...$sp);
echo implode(",", $f), "\n";

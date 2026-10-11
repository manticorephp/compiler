<?php
// A dynamic-name call whose candidate set has an inline-only (variadic) method yields an empty result for a name that exists only on a subclass or on the variadic base method
// issue: #183
class Base
{
    public function __call_marker(): string { return 'base'; }
    public function variadic(string ...$parts): string { return implode('+', $parts); }
    public function byRef(array &$a): void { $a[] = 1; }
}

class Child extends Base
{
    public function onlyHere(string $x): string { return "child:$x"; }
    public function other(): static { return $this; }
}

function pick(Base $b, array $names, int $i): string
{
    $m = $names[$i];
    return $b->{$m}('v');
}

$c = new Child();
echo pick($c, ['onlyHere', 'variadic'], 0), "\n";
echo pick($c, ['onlyHere', 'variadic'], 1), "\n";

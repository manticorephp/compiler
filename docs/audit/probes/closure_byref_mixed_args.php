<?php

// Argument evaluation happens EXACTLY ONCE under the dynamic by-ref path. The
// operand is a `select` between a value and an address precisely so that no
// branch duplicates the argument expression — `$i++` and a method call in
// argument position must each run one time per call.

final class Counter
{
    public int $calls = 0;

    public function next(): int
    {
        $this->calls++;
        return $this->calls * 10;
    }
}

$c = new Counter();
$i = 0;

$f = static function (int $a, ?array &$out, int $b): int {
    $out = [$a, $b];
    return $a + $b;
};
/** @var \Closure $erased */
$erased = $f;

echo $erased($i++, $bag, $c->next()), "\n";
var_dump($bag);
var_dump($i);
var_dump($c->calls);

echo $erased($i++, $bag2, $c->next()), "\n";
var_dump($bag2);
var_dump($i);
var_dump($c->calls);

// A by-ref slot fed an ALREADY-DEFINED local: the callee's write must reach
// the caller's variable, not a copy. The variable is initialised to NULL, which
// is what makes its slot erased — a concretely-typed one (`$pre = ['stale']`)
// cannot hold an array whose element type the callee chooses later, and the
// compiler says so by name instead of walking ints as pointers.
$pre = null;
echo $erased(1, $pre, 2), "\n";
var_dump($pre);

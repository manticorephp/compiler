<?php

// A `match` inside a captureless single-expression closure that is invoked
// directly is INLINED at the call site, so the closure's parameter loads are
// substituted with the arguments. The substitution walker used to carry its own
// hand-written node-kind list with no `match` arm: the spliced body kept the
// parameter load and read a local the caller never defined.

$f = static fn ($p) => match ($p) {
    'a' => 1,
    'b' => 2,
    default => 0,
};
echo $f('a'), "\n";
echo $f('b'), "\n";
echo $f('z'), "\n";

$g = function ($p) { return match (true) { $p > 10 => 'big', default => 'small' }; };
echo $g(42), "\n";
echo $g(1), "\n";

// The same shape one level down: a match arm whose body is itself a call, and a
// match with no `default` arm (its arms carry conditions, so the cloned arm list
// is the interesting one).
$h = static fn ($p) => match ($p[0]) {
    '"' => $p,
    "'" => strtoupper($p),
};
echo $h('"quoted'), "\n";
echo $h("'single"), "\n";

echo implode(',', array_map(static fn ($part) => match ($part) {
    'x' => 'X',
    default => $part . '!',
}, ['x', 'y', 'z'])), "\n";

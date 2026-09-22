<?php

// `fn() => yield $x` IS a generator: an arrow function is `function (…) use (…)
// { return <expr>; }`, and a yield in that expression makes the body one, the
// same as in any closure. Lowering hard-coded arrow fns as non-generators, so
// the emitter refused them outright ("yield outside a generator").
//
// `fn() => yield from […]` is NOT covered here: `return yield from` produces
// invalid IR in a NAMED function too, so that is a separate pre-existing bug
// and not this one.

$one = fn() => yield 42;
$it = $one();
foreach ($it as $v) {
    echo 'one: ', $v, "\n";
}
echo 'class: ', get_class($it), "\n";

$keyed = fn(string $k, int $v) => yield $k => $v;
foreach ($keyed('a', 1) as $k => $v) {
    echo 'keyed: ', $k, '=', $v, "\n";
}

// A plain arrow fn is still an ordinary closure — the yield scan must not leak
// out of the one that had it.
$plain = fn(int $x): int => $x * 2;
echo 'plain: ', $plain(21), "\n";

// An arrow fn nested INSIDE a generator must not claim the enclosing yield, and
// the enclosing function must stay a generator.
function outer(): \Generator
{
    $f = fn(int $x): int => $x + 1;
    yield $f(1);
    yield $f(10);
}

foreach (outer() as $v) {
    echo 'outer: ', $v, "\n";
}

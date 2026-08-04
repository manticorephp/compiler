<?php

// An arrow function captures every FREE variable in its body — including one
// that only appears as a spread OPERAND. The free-variable scan is a
// hand-written node-kind list and had no arm for `Spread`, so `...$xs` was
// invisible: the closure captured nothing and `$xs` dangled inside it.
//
// symfony/type-info spreads a VARIADIC parameter into exactly that shape:
//   static fn (Type $type): bool => $type->isIdentifiedBy(...$identifiers);

final class Bag
{
    public function has(string ...$identifiers): bool
    {
        foreach ($identifiers as $i) {
            if ($i === 'x') { return true; }
        }
        return false;
    }

    public function spec(string ...$identifiers): \Closure
    {
        return static fn (Bag $b): bool => $b->has(...$identifiers);
    }
}

$b = new Bag();
var_dump(($b->spec('a', 'x'))($b));
var_dump(($b->spec('a', 'b'))($b));

// The same through a free function, and with a plain array rather than a
// variadic — the operand is what matters, not where it came from.
function joinAll(string $sep, string ...$parts): string
{
    return implode($sep, $parts);
}

$parts = ['one', 'two', 'three'];
$f = fn (string $sep): string => joinAll($sep, ...$parts);
echo $f('-'), "\n";
echo $f('+'), "\n";

// A spread inside a `new`, and one inside an array literal, reach the scan
// through different arms.
final class Joined
{
    public string $s;
    public function __construct(string ...$bits) { $this->s = implode(',', $bits); }
}
$bits = ['p', 'q'];
$mk = fn (): Joined => new Joined(...$bits);
echo $mk()->s, "\n";

$tail = [3, 4];
$lit = fn (): array => [1, 2, ...$tail];
var_dump($lit());

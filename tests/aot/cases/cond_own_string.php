<?php

// A string conditional must hand out an OWNED (+1) value from every arm: a
// borrowed arm (alias / param / property / element) retained, a fresh arm
// (concat / call) transferred. Every read is followed by a fresh allocation so a
// freed block would be handed back and print the wrong bytes.

function churn(int $n): string
{
    $s = '';
    for ($i = 0; $i < $n; $i = $i + 1) { $s = $s . 'x'; }
    return $s;
}

// The accumulator: one borrowed arm, one fresh concat arm, in a loop.
function joinParts(array $parts): string
{
    $out = '';
    foreach ($parts as $p) {
        $piece = \strtoupper($p);
        $out = $out === '' ? $piece : ($out . ',' . $piece);
        churn(16);
    }
    return $out;
}

echo joinParts(['a', 'bb', 'ccc']), "\n";

// return of a ternary whose taken arm is a local — the `string|false` idiom.
function pick(bool $c): string
{
    $d = \str_repeat('y', 5);
    return $c === false ? 'F' : $d;
}

$r = pick(false);
churn(32);
echo $r, ' ', \strlen($r), "\n";

$fn = function (bool $c): string {
    $d = \str_repeat('z', 5);
    return $c ? 'C' : $d;
};
$rc = $fn(false);
churn(32);
echo $rc, ' ', \strlen($rc), "\n";

// Short ternary: the condition IS the then-arm.
function shortPick(string $a): string
{
    $b = \strtolower($a);
    return $b ?: 'fallback';
}
$sp = shortPick('MIXED');
churn(24);
echo $sp, "\n";
echo shortPick(''), "\n";

// Arg position and concat operand.
function lens(string $s): int { return \strlen($s); }
$k = 'kept';
$n = lens(true ? $k : 'other');
churn(16);
echo $n, ' ', $k, "\n";
$cat = 'pre-' . (false ? 'no' : $k) . '-post';
churn(16);
echo $cat, ' ', $k, "\n";

// Property and element destinations.
class Holder
{
    public string $s = '';
    /** @var string[] */
    public array $xs = [];
}
$h = new Holder();
$src = \str_repeat('p', 4);
$h->s = true ? $src : 'lit';
$h->xs[] = false ? 'lit' : $src;
$src = 'rebound';
churn(48);
echo $h->s, ' ', $h->xs[0], ' ', $src, "\n";

// `??` over a local, a missing key, and a nullable property.
$map = ['k' => \str_repeat('m', 3)];
$v = $map['k'] ?? 'def';
$missing = $map['nope'] ?? \str_repeat('d', 3);
churn(32);
echo $v, ' ', $missing, "\n";

// match arms: borrowed and fresh, in a loop.
function label(int $i, string $borrowed): string
{
    return match ($i) {
        0 => $borrowed,
        1 => \strrev($borrowed),
        default => 'other',
    };
}
$acc = '';
for ($i = 0; $i < 3; $i = $i + 1) {
    $b = \str_repeat('q', $i + 1);
    $acc = $acc . label($i, $b) . '|';
    churn(16);
}
echo $acc, "\n";

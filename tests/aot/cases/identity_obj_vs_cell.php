<?php
// `$obj === $erased` is a pointer compare, but one side arrives NaN-boxed (a
// generator's yielded value, a `mixed` slot) and the other raw — comparing the
// carriers verbatim NEVER matched. symfony's Table marks its header/body
// boundary with `if ($divider === $row)`, so the divider was never recognised,
// `$isHeader` stayed true and a header rule was drawn above every single row.

class Sep {}
class Other {}

function rows(array $items): Generator
{
    foreach ($items as $it) { yield $it; }
}

$div = new Sep();
$other = new Other();
$items = [['a'], $div, ['b'], $other];

$seen = '';
foreach (rows($items) as $row) {
    if ($div === $row) { $seen = $seen . 'D'; continue; }
    if ($other === $row) { $seen = $seen . 'O'; continue; }
    $seen = $seen . 'r';
}
echo $seen, "\n";

// !== is the exact complement.
$n = 0;
foreach (rows($items) as $row) { if ($div !== $row) { $n = $n + 1; } }
echo $n, "\n";

// Through an untyped param, and against an array element.
function countHits($rows, $needle): int
{
    $c = 0;
    foreach ($rows as $r) { if ($needle === $r) { $c = $c + 1; } }
    return $c;
}
echo countHits(rows($items), $div), countHits($items, $div), countHits($items, $other), "\n";

// A cell that is NOT an object is never identical to one.
function firstOf(array $a) { return $a[0]; }
$mixed = [$div, 'str', 42, [1, 2]];
$hits = '';
foreach ($mixed as $m) { $hits = $hits . (($div === $m) ? '1' : '0'); }
echo $hits, "\n";

// Two distinct instances of the same class stay distinct.
$a = new Sep();
$b = new Sep();
var_dump($a === $b, $a === $a);
echo "done\n";

<?php

// `unset($ref)` on a reference breaks THAT BINDING. The aliased variable keeps
// its value — php destroys the name, never the storage behind it. The alias
// shares the source's slot here, so zeroing the slot wiped the source instead.

function viaAlias(int $upto): int
{
    $bag = [];
    for ($i = 0; $i < $upto; $i = $i + 1) {
        $ref = &$bag;
        $ref[$i * 5] = $i;
        unset($ref);
    }
    $t = 0;
    foreach ($bag as $k => $v) { $t = $t + $k - $v; }
    return $t;
}

echo viaAlias(10), "\n";

// Scalars, and the name reused after the unbind: the second `$r` is a fresh
// local, so writing it must NOT reach `$x` any more.
$x = 1;
$r = &$x;
$r = 2;
echo $x, "\n";
unset($r);
$r = 99;
echo $x, ' ', $r, "\n";

// Rebinding an alias to a second source, then unsetting it.
$a = 10;
$b = 20;
$p = &$a;
$p = 11;
$p = &$b;
$p = 21;
unset($p);
echo $a, ' ', $b, "\n";

// An alias that was a real local first: unset gives that local back.
function reclaim(): string
{
    $own = 'mine';
    $src = 'src';
    $own = &$src;
    $own = 'written-through';
    unset($own);
    $own = 'after';
    return $src . '|' . $own;
}

echo reclaim(), "\n";

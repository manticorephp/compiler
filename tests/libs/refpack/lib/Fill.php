<?php

namespace Acme;

// A by-ref variadic declared in a LIBRARY: the application packs references
// from the interface `.sig` alone, so the writes reach its variables.
final class Fill
{
    public static function prefix(string $p, &...$slots): void
    {
        foreach ($slots as &$s) { $s = $p . $s; }
    }

    public function first(&...$slots): void { $slots[0] = 'w'; }
}

function bump(int $by, &...$nums): void
{
    foreach ($nums as $i => $_) { $nums[$i] = $nums[$i] + $by; }
}

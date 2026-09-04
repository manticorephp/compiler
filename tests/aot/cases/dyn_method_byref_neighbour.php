<?php

/**
 * A by-ref method must poison only its OWN name, not the whole dynamic-call
 * site. `Sink::collect(array &$r)` sits beside ordinary handlers; the erased
 * `$o->$m($x)` site keeps the shared helper for the clean names and the inline
 * path for `collect`, and BOTH must still behave.
 */

class Sink
{
    /** @param array<int, int> $r */
    public function collect(array &$r): int { $r[] = 99; return \count($r); }
}

class Alpha
{
    public function run(mixed $x): string { return 'alpha ' . (string)$x; }
}

class Beta
{
    public function run(mixed $x): string { return 'beta ' . (string)$x; }
}

function callDyn(mixed $cb, mixed $x): mixed
{
    $o = $cb[0];
    $m = $cb[1];
    return $o->$m($x);
}

echo callDyn([new Alpha(), 'run'], 1), "\n";
echo callDyn([new Beta(), 'run'], 2), "\n";

$bucket = [];
$s = new Sink();
echo $s->collect($bucket), "\n";
echo \count($bucket), ' ', $bucket[0], "\n";

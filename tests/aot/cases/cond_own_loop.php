<?php

// The balance witness: destructor ORDER and TIMING across loop iterations. A
// missing arm retain destructs early (or crashes), a double retain never
// destructs at all, and the `$x = null;` seed must not stop the local from
// owning what the conditional hands it.

class N_
{
    public function __construct(public string $t) {}
    public function __destruct() { echo 'drop ', $this->t, "\n"; }
}

for ($i = 0; $i < 3; $i = $i + 1) {
    $a = new N_('a' . $i);
    $x = null;
    $x = ($i % 2 === 0) ? $a : new N_('b' . $i);
    echo 'use ', $x->t, "\n";
    unset($x);
    echo 'after unset: ', $a->t, "\n";
    unset($a);
    echo "--\n";
}

// Strings: the accumulator shape, with the borrowed arm reused after the join.
$out = '';
$seen = [];
foreach (['one', 'two', 'three'] as $w) {
    $piece = \strtoupper($w);
    $out = $out === '' ? $piece : ($out . '+' . $piece);
    $seen[] = $piece;
    $piece = 'clobbered';
}
echo $out, "\n";
echo \implode(',', $seen), "\n";

// match in a loop, borrowed arm on every other pass.
function tag(int $i, N_ $borrowed): N_
{
    return match ($i % 2) {
        0 => $borrowed,
        default => new N_('m' . $i),
    };
}
for ($i = 0; $i < 2; $i = $i + 1) {
    $src = new N_('s' . $i);
    $t = tag($i, $src);
    echo 'tag ', $t->t, "\n";
    unset($t);
    echo 'src ', $src->t, "\n";
    unset($src);
}

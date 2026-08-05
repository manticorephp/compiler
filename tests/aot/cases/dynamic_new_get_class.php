<?php

// `new $cls()` produces a value the MIR types CELL, but the emitter stored the
// bare `ptrtoint` — so every consumer that checks the NaN-box TAG rather than
// masking it saw a non-object. get_class() answered '' (its tag!=8 arm is the
// default) while property reads looked fine, because cellToPtr's 48-bit mask
// leaves a raw pointer unchanged.

class Alpha { public int $n = 1; public function who(): string { return 'A'; } }
class Beta extends Alpha { public int $n = 2; public function who(): string { return 'B'; } }

$c = 'Alpha';
$a = new $c();
echo get_class($a), " ", $a->who(), " ", $a->n, "\n";
var_dump($a instanceof Alpha, is_object($a));

$d = 'Beta';
$b = new $d();
echo get_class($b), " ", $b->who(), " ", $b->n, "\n";
var_dump($b instanceof Alpha, $b instanceof Beta);

// through a mixed channel as well
function mk(string $k): mixed { return new $k(); }
foreach (['Alpha', 'Beta'] as $k) {
    $o = mk($k);
    // NB: get_debug_type() is deliberately NOT asserted here — it answers
    // 'object' for an erased receiver instead of the class name. Separate
    // path from get_class(), and a separate gap.
    echo get_class($o), " ", $o->who(), "\n";
}

// a statically-constructed object must keep naming itself
echo get_class(new Beta()), "\n";

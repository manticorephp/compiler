<?php
// A bare-`array` param/return over a MIXED-value array (assoc[string,cell]).
// Monomorphize used to decline such a call site — a cell element is not
// "concrete" — so the raw array pointer rode an `unknown` param and an
// `unknown` return with no tag: is_array() said false and var_dump printed the
// pointer as an int. A homogeneous array always worked (it IS concrete), which
// is what hid this.
function idr(array $a): array { return $a; }
function idc(array $a): array { $o = $a; return $o; }

$mix = ['a' => 'x', 'b' => 7];
$t = idc($mix);
echo is_array($t) ? "yes" : "no", " ", count($t), "\n";
echo $t['a'], " ", $t['b'], "\n";
var_dump(idr($mix));
var_dump(idc(['a' => 'x', 'b' => 'y']));

// The same erasure through a VARIADIC pack: the caller builds the pack with a
// known element type, but the declared param is vec[unknown], so the inner
// foreach read the buffer untyped and every string key came back garbage.
function merge_all(array $a, array ...$others): array {
    $out = $a;
    foreach ($others as $o) {
        foreach ($o as $k => $v) { $out[$k] = $v; }
    }
    return $out;
}
$m = merge_all(['name' => 'old', 'keep' => 7], ['name' => 'new', 'added' => 9]);
var_dump($m);

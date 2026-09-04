<?php

// php's array `+` is the UNION operator: the left array's keys win, the right
// fills the gaps. It was already implemented, but two things kept it from
// firing on real code.
//
// 1. `new $cls(...)` had NO inference arm at all, so every node under a dynamic
//    `new` kept its default `unknown` — `$this`, an array literal, everything.
//    With both operands erased, the `+` fell through to ARITHMETIC and emitted
//    `add i64` on two array pointers, which clang rejects outright.
//    symfony/cache CacheItem::pack is the shape:
//      new $valueWrapper($this->value->value, $m + ['expiry' => …] + …)
// 2. An operand arriving as a CELL was handed to `__mir_array_union` still
//    NaN-boxed — `coerceToPtr` only inttoptrs, it does not strip a tag — so the
//    helper dereferenced the tag bits.

// ⚠ Only the KEYS are asserted for the erased operand. The elements it
// contributes stay NaN-boxed while the raw side's do not, so the result mixes
// two representations in one array — the standing element-repr root, witnessed
// on its own by tests/audit/probes/cap_bare_array_channel_elems.php. What this
// case pins is that the union HAPPENS: right keys fill the gaps, left keys win.
function wrap(mixed $carrier, array $base): array
{
    // $carrier->extra is an ERASED read: the union's right operand is a cell.
    return $base + ['expiry' => 7] + $carrier->extra;
}

final class Carrier
{
    public function __construct(public mixed $extra) {}
}

$r = wrap(new Carrier(['z' => 9, 'a' => 'shadowed']), ['a' => 1]);
var_dump(count($r));
var_dump(array_keys($r));
var_dump($r['a'], $r['expiry']);

// Left keys win, and a union NEVER renumbers.
$l = [1, 2];
$m = [10, 20, 30];
var_dump($l + $m);

// An empty left side copies the right verbatim.
var_dump([] + ['k' => 'v']);

// `+=` is the same operator.
$acc = ['k' => 1];
$acc += ['k' => 5, 'j' => 6];
var_dump($acc);

// Through a dynamic `new`, which is what stopped inferring in the first place.
final class Box
{
    public function __construct(public array $items) {}
}
$cls = 'Box';
$b = new $cls(['a' => 1] + ['b' => 2]);
var_dump(count($b->items));

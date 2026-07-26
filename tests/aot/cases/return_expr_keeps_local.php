<?php

// The return path releases every owned rc local except the one being handed back —
// but "the one being handed back" was recognised ONLY for a bare `return $x;`. So
//
//     return $d === false ? 'fallback' : $d;
//
// released $d and returned it: a use-after-free. It stayed invisible whenever the
// caller read the value before anything else allocated, and became visible the moment
// something did — a closure return (which boxes) was enough:
//
//     $fn = function (): string { $d = str_repeat('x', 5); return $d === false ? 'F' : $d; };
//     $fn();   // string(5) "5"   ← bytes of an unrelated allocation
//
// That is the canonical `string|false` idiom, so it sat under every stream read in the
// async epic: a task freading 5 bytes handed back the integer 5.

function viaTernary(): string
{
    $d = str_repeat('x', 5);
    return $d === false ? 'fallback' : $d;
}
var_dump(viaTernary());

// In a CLOSURE (the shape that exposed it — the return is boxed on the way out).
$closure = function (): string {
    $d = str_repeat('y', 6);
    return $d === false ? 'fallback' : $d;
};
$v = $closure();
$churn = str_repeat('Z', 6) . '!';          // reuse the block if it was freed
var_dump($v, $v === 'yyyyyy', $churn);

// Through an untyped slot, so the value is decoded by tag rather than by signature.
$slot = $closure;
$w = $slot();
$more = str_repeat('Q', 6) . '?';
var_dump((string)$w === 'yyyyyy', $more);

// The short ternary `?:` — its "then" arm IS the condition value.
function viaShortTernary(): string
{
    $d = str_repeat('s', 4);
    return $d ?: 'fallback';
}
var_dump(viaShortTernary());

// `??` picks one of its operands the same way.
function viaCoalesce(?string $in): string
{
    $d = str_repeat('c', 3);
    return $in ?? $d;
}
var_dump(viaCoalesce(null));
var_dump(viaCoalesce('given'));

// Nested ternaries, and an arm that is a fresh string rather than a local.
function nested(int $k): string
{
    $a = str_repeat('a', 2);
    $b = str_repeat('b', 3);
    return $k === 0 ? $a : ($k === 1 ? $b : ($a . $b));
}
var_dump(nested(0), nested(1), nested(2));

// A local returned through a ternary AND used again afterwards is still intact.
function bothUses(): string
{
    $d = str_repeat('m', 3);
    $out = true ? $d : 'other';
    return $out . '|' . $d . '|' . strlen($d);
}
var_dump(bothUses());
echo "done\n";

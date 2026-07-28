<?php

// An array LITERAL mixing int and string keys. Its key type used to be the
// `unionWith` of the element key kinds, and `string ∪ int` collapses to
// UNKNOWN — which reads as neither an assoc (isAssoc() is string-key-only) nor
// a cell-keyed array, so foreach picked the raw i64 key channel and a string
// key came back as its pointer. Such a literal must ride a tagged CELL key.
//
// Three distinct carriers are exercised, because each lost the key in its own
// place: the literal itself, a by-value `array` param (the call-site element
// refinement rebuilt `vec(elem)` and dropped the key), and a variadic pack
// (refined only when Monomorphize happened to clone the callee).

// 1. The literal itself — explicit int key, and the positional form.
$a = ['color' => 'red', 5 => 'five'];
print_r($a);
foreach ($a as $k => $v) { var_dump($k); }

$b = [10, 'color' => 'blue'];
print_r($b);
foreach ($b as $k => $v) { var_dump($k); }

// A single-kind literal must be untouched by the mixed-key rule.
$c = ['x' => 1, 'y' => 2];
foreach ($c as $k => $v) { var_dump($k); }
$d = [1, 2, 3];
foreach ($d as $k => $v) { var_dump($k); }

// 2. Through a by-value `array` param: re-keying the argument must keep the
// string key a string and the int key an int.
function rekey(array $in): array
{
    /** @var array<array-key, mixed> $out */
    $out = [];
    foreach ($in as $k => $v) {
        if (\is_int($k)) { $out[] = $v; } else { $out[$k] = $v; }
    }
    return $out;
}
print_r(rekey(['color' => 'red', 5 => 'five']));
print_r(rekey([10, 'color' => 'blue']));

// 3. Through a VARIADIC pack, with a single call site (so the callee is not
// cloned and the pack element has to be refined by the call-site scan).
function rekey_all(array $first, array ...$rest): array
{
    /** @var array<array-key, mixed> $out */
    $out = [];
    foreach ($first as $k => $v) {
        if (\is_int($k)) { $out[] = $v; } else { $out[$k] = $v; }
    }
    foreach ($rest as $one) {
        foreach ($one as $k => $v) {
            if (\is_int($k)) { $out[] = $v; } else { $out[$k] = $v; }
        }
    }
    return $out;
}
print_r(rekey_all(['color' => 'red', 5 => 'five'], [10, 'color' => 'blue']));

// A mixed-key literal nested one level down.
print_r(rekey_all(['outer' => ['color' => 'red', 5 => 'five'], 1]));

<?php
/**
 * A variadic callee whose PACK PARAMETER stays ERASED — two incompatible
 * element shapes reach it at sites neither of which can be specialized — fed
 * an OWNED producer whose own static type IS a concrete `vec[string]`.
 *
 * The erased body co-owns a pack element with the runtime REPR walk, over
 * ownership bits a literal never stamps: it takes the buffer and nothing
 * inside it, while `$out[] = $v` copies the values out with no reference of
 * their own. A caller that drops that element by the ARGUMENT's own flavor
 * frees the strings the callee has just returned — the shape that killed the
 * symfony-demo T5 build inside `LowerFromAst::collectVars`.
 */

function pe_merge(array ...$xs): array
{
    $out = [];
    foreach ($xs as $x) {
        foreach ($x as $v) { $out[] = $v; }
    }
    return $out;
}

/** An ERASED accumulator: a bare `array` return has no element type. */
function pe_empty(): array
{
    return [];
}

/** @return string[] */
function pe_leaf(int $i): array
{
    return ['n' . $i, 'm' . $i];
}

/** The second element shape, at an equally unspecializable site. */
function pe_ints(): array
{
    return [1, 2];
}

/** @return string[] */
function pe_collect(int $n): array
{
    $out = pe_empty();
    for ($i = 0; $i < $n; $i++) {
        $out = pe_merge($out, pe_leaf($i));
    }
    return $out;
}

$junk = pe_merge(pe_empty(), pe_ints());

$seen = [];
$free = [];
foreach (pe_collect(4) as $v) {
    if (isset($seen[$v])) { continue; }
    $seen[$v] = true;
    $free[] = $v;
}
echo \count($junk), ' ', \count($free), "\n";
foreach ($free as $f) { echo $f, ' ', \strlen($f), "\n"; }

<?php
// A local joined from null and an array is erased (unknown) and holds the RAW
// array word. A `?array` / `mixed` return boxing it must recognise the container
// instead of int-boxing the pointer — Linux arm64 heap addresses (0xaaaa…) do not
// survive an int cell, so every async/ws program crashed the compiler there.
final class M { /** @param int[] $params */ public function __construct(public string $name, public array $params) {} }
/** @param M[] $ms @return array<int,int>|null */
function pick(array $ms, string $want): ?array {
    $found = null;
    foreach ($ms as $m) {
        if ($m->name !== $want) { continue; }
        $mp = $m->params;
        if (count($mp) === 0) { return null; }
        if ($found !== null && count($found) !== count($mp)) { return null; }
        $found = $mp;
    }
    return $found;
}
function last(array $a): mixed { $v = null; foreach ($a as $x) { $v = $x; } return $v; }
$r = pick([new M('a', [1]), new M('b', [7, 8, 9])], 'b');
var_dump(is_array($r), count($r), $r[2]);
var_dump(last([-1]), last([-5, 'str']), last([[1, 2]]), last([new ArrayObject([])]) instanceof ArrayObject, last([2.5]), last([]));
foreach ([[-7], [[3]]] as $in) { var_dump(last($in)); }

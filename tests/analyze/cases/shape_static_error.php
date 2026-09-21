<?php
final class Node { public function __construct(public int $kind) {} }

/** @param array{0:Node,1:bool} $pair */
function first(array $pair): Node { return $pair[0]; }

/** @param array{name: string, hits: int} $rec */
function hits(array $rec): int { return $rec['hits']; }

/** @return array{0:Node,1:bool} */
function make(): array { return [1, 'x']; }

/** @param array{0:Node,1:bool} $pair */
function store(array $pair): int
{
    $pair[0] = 'not a node';
    return $pair[1] ? 1 : 0;
}

/** @param array{0:Node,1:bool} $pair */
function store2(array $pair): int
{
    $pair[0] = true;
    return $pair[1] ? 1 : 0;
}

/** @param array{name: string, hits: int} $rec */
function probe(array $rec): int { return isset($rec['extra']) ? 1 : ($rec['extra'] ?? 0); }

/** @param array{0:Node,1:bool} $pair */
function missing(array $pair): int { return $pair[2]; }

echo first([1, 'x'])->kind;
echo hits(['name' => 'n']);
echo hits(['name' => 'n', 'hits' => 1, 'extra' => 2]);
$v = [1, 2];
echo first($v)->kind;
/** @param array<int, array{0:int,1:int}> $pairs */
function pairs(array $pairs): int { return count($pairs); }
echo pairs([[1, 'a']]);

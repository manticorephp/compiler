<?php
final class Node { public function __construct(public int $kind) {} }

/** @param array{0:Node,1:bool} $pair */
function read_tuple(array $pair): int
{
    $n = $pair[0];
    $ok = $pair[1];
    return $ok ? $n->kind : 0;
}

/** @param array{name: string, hits: int, maybe?: Node} $rec */
function read_record(array $rec): string
{
    $m = $rec['maybe'] ?? null;
    if (isset($rec['maybe'])) { return $rec['name']; }
    return $rec['name'] . ':' . ($rec['hits'] + 1) . ($m === null ? '-' : '+');
}

/** @param array{0:Node,1:bool} $pair */
function read_dynamic(array $pair, int $i): mixed
{
    return $pair[$i];
}

/** @param array{0:Node,1:bool} $pair */
function destructure(array $pair): int
{
    [$n, $ok] = $pair;
    return $ok ? $n->kind : -1;
}

echo read_tuple([new Node(3), true]), read_record(['name' => 'a', 'hits' => 1]),
    destructure([new Node(2), true]), "\n";

<?php
final class Node { public function __construct(public int $kind) {} }

/** @param array{0:Node,1:bool} $pair */
function take(array $pair): int { return $pair[1] ? $pair[0]->kind : 0; }

/** @param array{0:Node,1:bool} $pair */
function mutate(array $pair): int
{
    $pair[1] = false;
    $pair[0] = new Node(9);
    return $pair[0]->kind;
}

/** @param array{0:Node,1:bool} $pair */
function widen(array $pair, int $i): int
{
    $pair[$i] = new Node(1);
    return count($pair);
}

function build(): int
{
    /** @var array{name: string, hits: int} $rec */
    $rec = ['name' => 'a', 'hits' => 1];
    return $rec['hits'] + strlen($rec['name']);
}

/** @param array{0:Node,1:bool} $p @param array{0:Node,1:bool} $q */
function merge(array $p, array $q, bool $c): int
{
    $r = $c ? $p : $q;
    return $r[0]->kind;
}

echo take([new Node(4), true]), mutate([new Node(1), true]), widen([new Node(1), true], 0),
    build(), merge([new Node(5), true], [new Node(6), false], true), "\n";

<?php
final class Node { public function __construct(public int $kind) {} }

/** @param array<int, array{0:Node,1:bool}> $loops */
function demote(array $loops): int
{
    $sum = 0;
    foreach ($loops as $pair) {
        if ($pair[1]) { $sum += $pair[0]->kind; }
    }
    return $sum;
}

/** @param array{0:Node,1:bool} $pair */
function first(array $pair): Node { return $pair[0]; }

$loops = [[new Node(1), true], [new Node(2), false], [new Node(4), true]];
echo demote($loops), "\n";
echo first([new Node(7), false])->kind, "\n";
var_dump(first($loops[1]) instanceof Node);

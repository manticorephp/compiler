<?php
final class Node { public function __construct(public int $kind) {} }

/** @param array{0:Node,1:bool} $pair */
function tuple_keyed(array $pair): int { return count($pair); }

/** @param array{Node, bool} $pair */
function tuple_positional(array $pair): int { return count($pair); }

/** @param list{string, int} $pair */
function list_shape(array $pair): int { return count($pair); }

/** @param array{name: string, tags: string[], hits?: int} $rec */
function record_optional(array $rec): int { return count($rec); }

/** @param array{a: array{b: int}, c: int|string} $nested */
function nested(array $nested): int { return count($nested); }

/** @param array{0:int, 1:int} $pair */
function homogeneous(array $pair): int { return count($pair); }

/** @param array{get: ?string, set: ?string} $hooks */
function nullable_fields(array $hooks): int { return count($hooks); }

/** @param array{id: int, ...} $open */
function unsealed(array $open): int { return count($open); }

/** @param array{0:int,1:int}|false $u */
function union_false(mixed $u): int { return 0; }

/** @param array<int, array{0:Node,1:bool}> $loops */
function outer(array $loops): int { return count($loops); }

/** @return array{0:string,1:int} */
function ret(): array { return ['x', 1]; }

echo tuple_keyed([new Node(1), true]), tuple_positional([new Node(1), false]),
    list_shape(['a', 1]), record_optional(['name' => 'n', 'tags' => []]),
    nested(['a' => ['b' => 1], 'c' => 2]), homogeneous([1, 2]),
    nullable_fields(['get' => null, 'set' => 's']), unsealed(['id' => 1]),
    union_false(false), outer([]), count(ret()), "\n";

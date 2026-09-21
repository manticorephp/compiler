<?php
final class Node { public function __construct(public int $kind) {} }
/** @param array{0:Node,1:bool} $pair */
function d1(array $pair): string { [$n, $ok] = $pair; return $n->kind . ($ok ? 'y' : 'n'); }
/** @param array{name: string, hits: int} $rec */
function d2(array $rec): string { ['name' => $nm, 'hits' => $h] = $rec; return $nm . ($h + 1); }
echo d1([new Node(2), true]), ' ', d2(['name' => 'k', 'hits' => 41]), "\n";

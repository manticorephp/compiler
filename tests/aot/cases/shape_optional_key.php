<?php
final class Node { public function __construct(public int $kind) {} }
/** @param array{name: string, extra?: string, node?: Node} $rec */
function opt(array $rec): string
{
    $e = $rec['extra'] ?? '-';
    $n = isset($rec['node']) ? $rec['node']->kind : 0;
    return $rec['name'] . $e . $n;
}
echo opt(['name' => 'a']), ' ', opt(['name' => 'b', 'extra' => 'x', 'node' => new Node(4)]), "\n";

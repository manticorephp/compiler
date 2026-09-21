<?php
final class Node { public function __construct(public int $kind) {} }
/** @param array{0:Node,1:bool} $pair */
function first(array $pair): Node { return $pair[0]; }
/** @param array{name: string, hits: int} $rec */
function hits(array $rec): int { return $rec['hits']; }

$lie = json_decode('[5, true]', true);
try { echo first($lie)->kind, "\n"; }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$rec = json_decode('{"name":"n","hits":"many"}', true);
try { echo hits($rec), "\n"; }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo hits(['name' => 'ok', 'hits' => 2]), "\n";

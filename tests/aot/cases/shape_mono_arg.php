<?php
final class Node { public function __construct(public int $kind) {} }
/** @param array{0:Node,1:bool} $p */
function first(array $p): Node { return $p[0]; }

$local = [new Node(1), true];
echo first($local)->kind, "\n";
$lie = json_decode('[5,true]', true);
try { echo first($lie)->kind, "\n"; }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo first([new Node(3), false])->kind, "\n";
$lying = [5, true];
try { echo first($lying)->kind, "\n"; }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }

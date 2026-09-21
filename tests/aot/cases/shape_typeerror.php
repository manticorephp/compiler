<?php
final class Node { public function __construct(public int $kind) {} }
/** @param array{0:Node,1:bool} $pair */
function first(array $pair): Node { return $pair[0]; }
/** @param array{0:Node,1:bool} $pair */
function second(array $pair): bool { return $pair[1]; }
/** @param array{name: string, hits: int} $rec */
function hits(array $rec): int { return $rec['hits']; }
/** @param array{name: string, hits: int} $rec */
function name(array $rec): string { return $rec['name']; }
/** @param array{tags: string[]} $rec */
function tags(array $rec): int { return count($rec['tags']); }
/** @param array{ratio: float} $r */
function ratio(array $r): float { return $r['ratio'] * 1.5; }

$lie = json_decode('[5, true]', true);
try { echo first($lie)->kind, "\n"; }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$rec = json_decode('{"name":"n","hits":"many"}', true);
try { echo hits($rec), "\n"; }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo hits(['name' => 'ok', 'hits' => 2]), "\n";
$rec = json_decode('{"name":null,"hits":1}', true);
try { echo name($rec), "\n"; }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$lie = json_decode('[1,"yes"]', true);
try { var_dump(second($lie)); }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$rec = json_decode('{"tags":5}', true);
try { echo tags($rec), "\n"; }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$rec = json_decode('{"ratio":"x"}', true);
try { echo ratio($rec), "\n"; }
catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo ratio(json_decode('{"ratio":2}', true)), "\n";

<?php
// A literal whose element is itself a literal of ARRAYS, stored as a cell
// (the outer array mixes kinds): the inner literal is rebuilt into a cell
// array that co-owned each row while the inner buffer was freed buffer-only,
// so every row leaked one reference per evaluation (php-cs-fixer's
// Tokens::getBlockEdgeDefinitions, ~39 arrays per findBlockEnd).
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
function defs(string $p): array {
    return [1 => ['start' => [new Tok($p . 's'), 1], 'end' => [new Tok($p . 'e'), 2]], 2 => 'x'];
}
function use1(string $p): int { $d = defs($p); return \count($d[1]['start']); }
echo use1('a'), "\n";
echo "after a\n";
echo use1('b'), "\n";
echo "after b\n";

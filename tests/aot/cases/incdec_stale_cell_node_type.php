<?php
// `--$j` over a local first inferred as a cell (`$j = $i`, a key not yet
// narrowed) kept the stale `cell` on the node, and the raw int slot went through
// the cell decrement: the inner loop stopped after one step.
function a(array $xs): int { $n = 0; foreach ($xs as $i => $x) { for ($j = $i; $j >= 0; --$j) { $n = $n + $j; } } return $n; }
function b(array $xs): int { $n = 0; foreach ($xs as $i => $x) { $j = $i; while ($j >= 0) { $n = $n + $j; $j--; } } return $n; }
function c(): int { $n = 0; foreach ([0, 1, 2, 3] as $i) { for ($j = $i; $j >= 0; --$j) { $n = $n + $j; } } return $n; }
function d(array $xs): int { $n = 0; foreach ($xs as $i => $x) { $n = $n + $i; } return $n; }
function e(array $xs): int { $n = 0; foreach ($xs as $i => $x) { for ($j = $i; $j >= 0; --$j) { echo "$i:$j "; } } echo "\n"; return $n; }
echo a(['a','b','c','d']), ' ', b(['a','b','c','d']), ' ', c(), ' ', d(['a','b','c','d']), "\n"; e(['a','b','c','d']);

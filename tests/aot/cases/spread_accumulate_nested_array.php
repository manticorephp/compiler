<?php
// Accumulating nested arrays with [...$acc, [..]] in a loop returns garbage floats instead of the rows.
function g(array $in) { $r = []; foreach ($in as $p) { $r = [...$r, [$p, 2]]; } return $r; }
echo json_encode(g([1, 2])), "\n";
function s(array $in) { $r = []; foreach ($in as $p) { $r = [...$r, $p . "z"]; } return $r; }
echo json_encode(s(["a", "b"])), "\n";

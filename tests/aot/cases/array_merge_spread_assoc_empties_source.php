<?php
// array_merge(...$assoc) of nested arrays empties the source's inner arrays
$l = [];
foreach ([[10, ['a', 1]], [5, ['b', 2]]] as [$p, $x]) { $l[$p][] = $x; }
$m = array_merge(...$l);
echo json_encode($l), "\n";

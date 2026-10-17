<?php
// array_push spreading an erased array of arrays into an int list literal throws TypeError instead of appending them.
// issue: #311
function build(): array { $l = []; $l[10][] = ['a', 1]; $l[5][] = ['b', 2]; return $l; }
$l = build();
$z = [0];
array_push($z, ...$l);
echo json_encode($z), "\n";

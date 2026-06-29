<?php
// Worklist: a user helper calling a polymorphic prelude fn specializes,
// then the prelude fn specializes per the helper's now-concrete element type.
function doubled(array $a) { return array_map(fn($x) => $x * 2, $a); }
function banged(array $a) { return array_map(fn($s) => $s . "!", $a); }
foreach (doubled([1, 2, 3]) as $v) { echo $v, " "; }
echo "\n";
foreach (banged(["x", "y"]) as $v) { echo $v, " "; }
echo "\n";

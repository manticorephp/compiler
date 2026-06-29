<?php
// Exercises the assoc buffer-rc path: each iteration builds an escaping
// (returned) assoc, reads it, and drops it. The release/retain discipline
// must keep every read correct across iterations (a premature free would
// corrupt the value or fault).
function row(int $i): array {
    $r = [];
    $r["id"] = $i;
    $r["sq"] = $i * $i;
    return $r;
}
$sum = 0;
for ($i = 0; $i < 1000; $i = $i + 1) {
    $r = row($i);
    $sum = $sum + $r["id"] + $r["sq"];
}
echo $sum, "\n";

// aliased read (borrow), assoc co-owned then both go out of scope
$cfg = ["a" => 10, "b" => 20];
$ref = $cfg;
echo $ref["a"] + $ref["b"], "\n";

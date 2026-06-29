<?php
// String rc: heap concat results (returned +1), fresh builtin/call-arg
// temporaries, and vec[string] elements are reference-counted and freed.
// The loop keeps memory flat; this test asserts correctness.
function label(int $n): string {
    return "row-" . $n;                 // heap concat, returned (+1 transfer)
}
function joined(string $a, string $b): string {
    return strtoupper($a . "-" . $b);   // concat temps + builtin arg temp
}
$total = 0;
$j = 0;
while ($j < 100) {
    $a = label($j);                     // owned heap string, freed at scope
    $b = joined($a, "x");               // nested concat/builtin temps freed
    $v = ["p", "qq", "rrr"];            // vec[string], freed (element drop)
    $total = $total + strlen($a) + strlen($b) + strlen($v[2]);
    $j = $j + 1;
}
echo $total, "\n";

<?php
// A local the LOOP BODY re-kinds. The back-edge merge used to collapse
// int ∪ float / int ∪ string / int ∪ cell to `unknown`, which reads back as a
// raw bit pattern — a float printed as its bits, a string as its pointer. The
// slot must ride a tagged cell instead. `if` had this (planMergeShadow); the
// loops did not.
$a = 0;
for ($i = 0; $i < 2; $i++) { $a = 1.5; }
echo "for-float: ", $a, "\n";

$b = 0;
for ($i = 0; $i < 2; $i++) { $b = "str"; }
echo "for-string: ", $b, "\n";

$c = 0;
$k = 0;
while ($k < 2) { $c = 3.5; $k++; }
echo "while-float: ", $c, "\n";

$d = 0;
$j = 0;
do { $d = "dw"; $j++; } while ($j < 2);
echo "dowhile-string: ", $d, "\n";

$e = 0;
foreach ([1, 2] as $v) { $e = 2.5; }
echo "foreach-float: ", $e, "\n";

// The read INSIDE the body sees the previous iteration's value — so the body
// must be typed on the MERGED slot, not on the pre-loop one.
$f = 1;
$seen = "";
for ($i = 0; $i < 3; $i++) {
    $seen = $seen . "|" . $f;
    $f = 2.5;
}
echo "carried: ", $seen, "\n";

// A cell-valued call (getenv is string|false) re-kinds an int slot the same way.
$g = 0;
for ($i = 0; $i < 2; $i++) { $g = getenv("PATH"); }
echo "getenv-in-loop: ", (is_string($g) && strlen($g) > 0) ? "string" : "broken", "\n";

// The zero-trip case: the loop never runs, so the slot still holds the pre-loop
// value — boxed, because the NAME is a cell now.
$h = 7;
for ($i = 0; $i < 0; $i++) { $h = "never"; }
echo "zero-trip: ", $h, "\n";

// A promoted PARAM arrives raw and must be boxed at entry.
function retypesParam(int $p): string
{
    for ($i = 0; $i < 2; $i++) { $p = "now-a-string"; }
    return (string)$p;
}
echo "param: ", retypesParam(5), "\n";

function zeroTripParam(int $p): string
{
    for ($i = 0; $i < 0; $i++) { $p = "never"; }
    return (string)$p;
}
echo "param-zero-trip: ", zeroTripParam(9), "\n";

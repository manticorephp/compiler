<?php
// loop — 50 M-iteration integer accumulate. A data-dependent running value
// ($acc feeds the next step) seeded by $argc (=1) defeats closed-form folding,
// so the loop actually executes.
$acc = $argc;
for ($i = 0; $i < 50000000; $i++) {
    $acc = ($acc * 3 + ($i & 7)) & 0x3FFFFFFF;
}
echo $acc, "\n";

<?php
// A foreach whose body builds confined (Arena) string temporaries resets
// the arena each iteration, so memory stays flat. This asserts the reset
// never frees a still-live value: the echoed strings and the running
// accumulator must remain correct across iterations.
$items = ["red", "green", "blue", "amber"];
$acc = 0;
foreach ($items as $x) {
    echo strtoupper("c-" . $x) . "\n";   // arena temps, reclaimed each iter
    $acc = $acc + strlen("c-" . $x);     // accumulator survives the reset
}
echo $acc, "\n";

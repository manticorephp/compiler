<?php
// A loop whose body builds confined (Arena) string temporaries resets
// the arena each iteration, so memory stays flat. This asserts the reset
// never frees a still-live value: the echoed strings and the running
// accumulator must remain correct across iterations.
$i = 0;
$acc = 0;
while ($i < 6) {
    echo strtoupper("item-" . $i) . "\n";   // arena temps, reclaimed each iter
    $acc = $acc + strlen("item-" . $i);      // accumulator survives the reset
    $i = $i + 1;
}
echo $acc, "\n";

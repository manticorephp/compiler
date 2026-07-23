<?php
use function Async\run;
use function Async\spawn;
use function Async\delay;

run(function () {
    // Closures capturing a loop variable by value must see DISTINCT values,
    // even though they all run after the loop (via delay).
    $tasks = [];
    for ($i = 0; $i < 4; $i++) {
        $tasks[] = spawn(function () use ($i) {
            delay(0.005);
            return $i;
        });
    }
    $out = "";
    foreach ($tasks as $t) {
        $out .= $t->await() . " ";
    }
    echo "captured: ", $out, "\n";   // expect: 0 1 2 3
});

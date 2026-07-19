<?php

// random_bytes / random_int are non-deterministic, so this asserts INVARIANTS
// (length, range, distinctness) rather than exact bytes — the output is stable
// across runtimes and difftests cleanly.

var_dump(strlen(random_bytes(1)));
var_dump(strlen(random_bytes(16)));
var_dump(strlen(random_bytes(300)));   // > 256, exercises the getentropy loop

// random_int stays in range across many draws.
$ok = true;
for ($i = 0; $i < 1000; $i = $i + 1) {
    $v = random_int(10, 20);
    if ($v < 10 || $v > 20) { $ok = false; }
}
var_dump($ok);

// degenerate range
var_dump(random_int(5, 5));

// a single-value and a large range stay in bounds
$v = random_int(0, 1);
var_dump($v === 0 || $v === 1);
$big = random_int(0, 1000000);
var_dump($big >= 0 && $big <= 1000000);

// two draws of 16 bytes should (essentially always) differ
var_dump(random_bytes(16) !== random_bytes(16));

// ValueError on min > max
try {
    random_int(10, 5);
    var_dump('no throw');
} catch (\ValueError $e) {
    var_dump('ValueError');
}

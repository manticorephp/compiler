<?php
// foreach by reference over a SplFixedArray iterates instead of throwing "An iterator cannot be used with foreach by reference"
// issue: #163
$a = new SplFixedArray(2);
$a[0] = 1; $a[1] = 2;
try {
    foreach ($a as &$r) { echo "iterated\n"; }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

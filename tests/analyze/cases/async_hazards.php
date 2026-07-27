<?php

use function Async\async;
use function Async\spawn;

// A mutable static local is ONE slot for the whole process — every fiber shares it.
function nextId(): int
{
    static $n = 0;
    $n = $n + 1;
    return $n;
}

// Read-only: a constant table, not shared mutable state. Must NOT be reported.
function table(): array
{
    static $t = ['a', 'b'];
    return $t;
}

// A static in a nested block still counts.
function counter(bool $on): int
{
    if ($on) {
        static $hits = 0;
        $hits = $hits + 1;
        return $hits;
    }
    return 0;
}

async(function () {
    spawn(function () {
        $a = file_get_contents('/etc/hostname');   // filesystem: blocks the loop
        $b = file_get_contents('https://example.com/x');  // network: async, fine
        $c = file_get_contents($GLOBALS['path']);  // computed: no claim made
        file_put_contents('/tmp/out', $a . $b . $c);
        $d = scandir('/tmp');
        return count($d);
    });
});

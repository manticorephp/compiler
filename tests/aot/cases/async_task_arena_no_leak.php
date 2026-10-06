<?php
// MANTICORE-ONLY (Async); expected output written by hand.
// A task runs on its own arena: the chunks and the mark stack go with the
// fiber's context when the task ends. @serial: a memory measurement.
use function Async\async;
function work(int $i): int { $o = 'abc' . $i . str_repeat('x', 50); return strlen($o); }
function batch(int $n): int
{
    $t = 0;
    \Async\group(function (\Async\TaskGroup $g) use ($n, &$t) {
        for ($i = 0; $i < $n; $i++) { $g->spawn(function () use ($i, &$t) { $t += work($i); }); }
    });
    return $t;
}
async(function () {
    $t = 0;
    for ($r = 0; $r < 4; $r++) { $t += batch(500); }
    $b = memory_get_usage();
    for ($r = 0; $r < 40; $r++) { $t += batch(500); }
    $d = memory_get_usage() - $b;
    echo $t > 0 ? 'ran' : 'idle', "\n";
    echo $d < 8 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($d / 1048576, 1) . 'MB', "\n";
});

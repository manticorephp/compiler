<?php
// Every spawned+awaited Task must be freed: Scheduler::step overwrites $this->running.
// @serial: a memory measurement.
function work(int $i): int { return $i * 2; }
$sum = Async\async(function (): int {
    $s = 0;
    for ($i = 0; $i < 2000; $i++) { $s += Async\spawn(work(...), $i)->await(); }
    $b = memory_get_usage();
    for ($i = 0; $i < 100000; $i++) { $s += Async\spawn(work(...), $i)->await(); }
    $g = memory_get_usage() - $b;
    echo $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
    return $s;
});
echo 'sum=', $sum, "\n";

<?php
// @serial: a memory measurement.
// `throw $e` of a caught exception out of an async task (a heap frame on its
// own fiber): moved into the task's failure, released once by its awaiter.
use function Async\async;
use function Async\spawn;
final class E extends Exception {
    public static int $live = 0;
    public string $pad;
    public function __construct(string $m) { parent::__construct($m); self::$live++; $this->pad = str_repeat('a', 256); }
    public function __destruct() { self::$live--; }
}
function round_(int $i): int {
    $t = spawn(function () use ($i): int {
        try { throw new E('t' . $i); } catch (E $e) { throw $e; }
    });
    try { $t->await(); } catch (E $e) { return strlen($e->getMessage()); }
    return 0;
}
async(function (): void {
    $sum = 0;
    for ($i = 0; $i < 500; $i++) { $sum += round_($i); }
    echo 'live=', E::$live, "\n";
    $b = memory_get_usage();
    for ($i = 0; $i < 5000; $i++) { $sum += round_($i); }
    $g = memory_get_usage() - $b;
    echo 'sum=', $sum, ' live=', E::$live, ' ', $g < 2 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
});

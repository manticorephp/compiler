<?php
// @serial: a memory measurement.
// A float stored into a cell-declared static slot releases what the slot held.
final class R { public static mixed $mix = null; public static $u = null; }
function run(string $what, callable $f): void {
    for ($i = 0; $i < 1000; $i++) { $f($i); }
    $b = memory_get_usage();
    for ($i = 0; $i < 50000; $i++) { $f($i); }
    $g = memory_get_usage() - $b;
    echo $what, ' ', $g < 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
}
run('mixed str then float', function (int $i) { R::$mix = str_repeat('m', 300) . $i; R::$mix = 1.5; });
run('untyped str then float', function (int $i) { R::$u = str_repeat('m', 300) . $i; R::$u = 2.5; });
run('untyped array then float', function (int $i) { R::$u = [str_repeat('m', 300) . $i]; R::$u = 0.5; });
echo R::$mix, ' ', R::$u, "\n";

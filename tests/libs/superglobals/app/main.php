<?php
// A superglobal written on both sides of the module boundary: the library's
// stores and the application's land in ONE slot of one representation.
$_GET = ['app' => 1];
\Acme\Gpc::set('lib', 'x');
echo \Acme\Gpc::keys(), "\n";
for ($i = 0; $i < 3; $i++) {
    \Acme\Gpc::reset($i);
    $_GET['app' . $i] = \str_repeat('a', 64);
    echo implode(',', array_keys($_GET)), "\n";
    $_GET = ['fresh' => $i];
}
echo \Acme\Gpc::keys(), ' ', $_GET['fresh'], "\n";

// Every store releases what it overwrites, on both sides of the boundary.
// memory_get_usage() is peak RSS natively, and a pooled allocator keeps its
// per-class high-water: the first batch may still raise it, a leak raises
// every batch.
function churn(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        \Acme\Gpc::reset($i);
        $_GET['app'] = \str_repeat('a', 200) . $i;
        $_GET = ['fresh' => \str_repeat('f', 200) . $i];
    }
}
$b0 = memory_get_usage();
churn(50000);
$b1 = memory_get_usage();
churn(50000);
$g = memory_get_usage() - $b1;
echo $g < 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB, first batch '
    . round(($b1 - $b0) / 1048576, 1) . 'MB', "\n";
if ($g >= 1024 * 1024 && is_readable('/proc/self/maps')) {
    // Where the memory went: the brk heap and every large anonymous mapping.
    foreach (explode("\n", (string)file_get_contents('/proc/self/maps')) as $l) {
        $p = explode(' ', $l);
        $r = explode('-', $p[0] ?? '');
        if (count($r) !== 2) { continue; }
        $sz = hexdec($r[1]) - hexdec($r[0]);
        if (str_contains($l, '[heap]') || ($sz >= 1048576 && trim($p[count($p) - 1]) === '')) {
            echo 'map ', $p[0], ' ', round($sz / 1048576, 1), "MB\n";
        }
    }
    foreach (explode("\n", (string)@file_get_contents('/proc/self/smaps_rollup')) as $l) {
        if (str_starts_with($l, 'Rss:') || str_starts_with($l, 'AnonHugePages:')) { echo $l, "\n"; }
    }
}

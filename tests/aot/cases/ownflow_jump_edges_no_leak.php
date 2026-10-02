<?php
// A local borrowed on one path and owned on another must be compensated on the
// jump edge itself — continue, break N, goto — and released once.
// @serial: a memory measurement.
/** @return int[] */
function mk(int $n): array { $r = []; for ($j = 0; $j < $n; $j++) { $r[] = $j; } return $r; }
/** @param int[] $seed */
function walk(array $seed, int $n): int {
    $c = 0;
    $vs = [1, 2, 3];
    for ($i = 0; $i < 4; $i++) {
        $a = $seed;
        if ($i % 2 === 0) { $a = mk(40 + $i); }
        foreach ($vs as $v) {
            $b = $seed;
            if ($v === 2) { $b = mk(30 + $v); continue; }
            if ($i === $n) { $a = mk(50); break 2; }
            $c += count($b);
        }
        $c += count($a);
    }
    $k = 0;
    $g = $seed;
    again:
    if ($k > 0) { $g = mk(20 + $k); }
    if (++$k < 3) { goto again; }
    return $c + count($g) + count($a);
}
$seed = [7, 8, 9];
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += walk($seed, $i % 5); }
$b = memory_get_usage();
for ($i = 0; $i < 100000; $i++) { $sum += walk($seed, $i % 5); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

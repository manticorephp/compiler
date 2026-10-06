<?php
// @serial: a memory measurement.
// An assoc read out of a static property into a local co-owns it: the local
// outlives the static's next overwrite, and the pair leaks nothing.
final class C { /** @var array<string,string> */ public static array $map = []; }
function step(int $i): int {
    C::$map = ['k' => str_repeat('m', 200) . $i, 'j' => 'x' . $i];
    $m = C::$map;
    C::$map = ['k' => 'other'];
    $junk = ['a' => str_repeat('z', 200)];
    return strlen($m['k']) + strlen($m['j']);
}
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += step($i); }
$b = memory_get_usage();
for ($i = 0; $i < 100000; $i++) { $sum += step($i); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 2 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";

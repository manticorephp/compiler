<?php
// A local that is borrowed on one path and owned on the other must be released
// exactly once after the join; today the borrowed store blocks the name forever.
// @serial: a memory measurement.
final class Big { public string $s; public function __construct(int $i) { $this->s = str_repeat('x', 512) . $i; } }
final class D { public function __destruct() { echo "D gone\n"; } }

/** @return int[] */
function mk(int $n): array { $r = []; for ($j = 0; $j < $n; $j++) { $r[] = $j; } return $r; }

function pick(Big $p, int $i): int {
    $x = $p;
    if ($i % 2 === 0) { $x = new Big($i); }
    $y = new Big($i);
    $y = $p;
    return strlen($x->s) + strlen($y->s);
}
/** @param int[] $p */
function pickArr(array $p, int $i): int {
    $x = $p;
    if ($i % 2 === 0) { $x = mk(60 + $i % 7); }
    return count($x);
}
function once(): void { $d = new D(); $e = $d; if (true) { $e = new D(); } echo "end once\n"; }

$p = new Big(0);
$a = [1, 2, 3];
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += pick($p, $i) + pickArr($a, $i); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { $sum += pick($p, $i) + pickArr($a, $i); }
$g = memory_get_usage() - $b;
echo 'sum=', $sum, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
once();
echo "after\n";

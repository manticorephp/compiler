<?php
// An int past the 48-bit inline cell form rides a heap box. The box is counted
// like any cell payload: boxing one per call (an object id, a nanosecond clock,
// a PCRE handle through a `mixed` slot) must not grow the process.

function pass(mixed $v): mixed { return $v; }
/** @param mixed[] $a */
function keep(array $a, mixed $v): array { $a[0] = $v; return $a; }

function measure(string $label, callable $body): void
{
    $big = 1 << 60;
    $s = 0;
    for ($i = 0; $i < 2000; $i++) { $s += $body($big, $i); }
    $before = memory_get_usage();
    for ($i = 0; $i < 300000; $i++) { $s += $body($big, $i); }
    $g = memory_get_usage() - $before;
    echo $label, ': sum=', $s, ' ', $g < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($g / 1048576, 1) . 'MB', "\n";
}

measure('mixed pass', function (int $big, int $i): int { return pass($big + $i) - $big; });
measure('array slot', function (int $big, int $i): int { $a = keep([0], $big + $i); return $a[0] - $big; });
measure('keyed', function (int $big, int $i): int { $m = [$big + $i => 1]; return array_key_first($m) - $big; });
$x = 1 << 50; $y = pass($x); $z = [$y, pass($y)];
var_dump($y === $x, $z[1] === $x, $z[0] + 1);
// A foreach value is a BORROW: reading a big-int one twice must not free it.
$vals = [1 << 60, (1 << 60) + 1];
$t = 0;
foreach ($vals as $v) { $t += ($v - (1 << 60)) + ($v - (1 << 60)); }
var_dump($t);
/** @param mixed[] $xs */
function m(array $xs): int { $s = 0; foreach ($xs as $x) { $s += pass($x) - (1 << 60); $s += $x - (1 << 60); } return $s; }
var_dump(m([1 << 60, (1 << 60) + 5, 'k' => (1 << 60) + 7]));

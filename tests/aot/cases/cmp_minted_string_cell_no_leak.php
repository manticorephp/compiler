<?php
// A string a compare operand mints (`(string)$i`, a concat) against a cell:
// every operator frees it. `array_key_exists()` with a string key kept one
// buffer per call through `(string)(int)$key === $key`. @serial: a memory measurement.
function s1(int|string $k, int $i): bool { return (string)$i === $k; }
function s2(int|string $k, int $i): bool { return (string)$i != $k; }
function s3(int|string $k, int $i): bool { return (string)$i < $k; }
function s4(int|string $k, int $i): int { return $k <=> (string)$i; }
function s5(mixed $k, int $i): bool { return ('p' . $i . 'q') == $k; }
function s6(array $o, string $k): bool { return \array_key_exists($k, $o); }
function turn(int $i): int
{
    $k = 'window';
    return (int)s1($k, $i) + (int)s2($k, $i) + (int)s3($k, $i) + s4($k, $i) + (int)s5($k, $i) + (int)s6(['a' => 1], $k);
}
$t = 0;
for ($i = 100000; $i < 102000; $i++) { $t += turn($i); }
$b = memory_get_usage();
for ($i = 100000; $i < 160000; $i++) { $t += turn($i); }
$d = memory_get_usage() - $b;
echo $t, "\n";
echo $d < 3 * 1024 * 1024 ? 'growth ok' : 'growth=' . round($d / 1048576, 1) . 'MB', "\n";

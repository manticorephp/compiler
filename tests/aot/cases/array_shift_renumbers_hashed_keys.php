<?php
declare(strict_types=1);

// array_shift renumbers the INTEGER keys from 0 and keeps the string ones,
// whatever the buffer's mode: a HASHED buffer (after an unset, or with a
// string key) kept its old int keys.

function dump(array $a): void
{
    $o = [];
    foreach ($a as $k => $v) { $o[] = var_export($k, true) . '=>' . var_export($v, true); }
    echo implode(', ', $o), "\n";
}

$a = ['a', 'b', 'c', 'd', 'e', 'f'];
unset($a[0], $a[1]);
echo array_shift($a), "\n";
dump($a);
$a[] = 'g';
dump($a);
var_dump($a[0], isset($a[3]), array_key_last($a));

$b = ['x' => 1, 5 => 2, 9 => 3, 'y' => 4, 12 => 5];
echo array_shift($b), "\n";
dump($b);
$b[] = 6;
dump($b);
var_dump($b[1], $b['y']);

$c = [10 => 'p', 20 => 'q', 30 => 'r'];
next($c);
next($c);
array_shift($c);
var_dump(current($c), key($c));
dump($c);

$big = [];
for ($i = 0; $i < 40; $i++) { $big[$i * 3] = $i; }
array_shift($big);
var_dump($big[0], $big[38], isset($big[39]), \count($big));

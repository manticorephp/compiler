<?php
final class Tg { public function __construct(public string $name = '') {} }
function parseTyped(string $n, ?Tg &$attribute = null): string
{
    $attribute = null;
    if ($n === 'x') { $attribute = new Tg($n); }
    return $n;
}
function parseMixed(string $n, mixed &$attribute = null): string
{
    $attribute = null;
    if ($n === 'x') { $attribute = new Tg($n); }
    return $n;
}
foreach (['a', 'b'] as $k) { echo $k; }
echo "\n";
foreach (['p', 'x'] as $n) {
    $k = null;
    echo parseTyped($n, $k), ' ', get_debug_type($k), "\n";
}
foreach (['p', 'x'] as $n) {
    $k = null;
    echo parseMixed($n, $k), ' ', get_debug_type($k), "\n";
}

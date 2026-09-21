<?php
/** @param array{a: array{b: int, c: string}, d: int|string} $n */
function nested(array $n): string
{
    $inner = $n['a'];
    return $inner['b'] . $inner['c'] . $n['a']['b'] . (is_int($n['d']) ? 'i' : 's');
}
echo nested(['a' => ['b' => 1, 'c' => 'z'], 'd' => 5]), "\n";
echo nested(['a' => ['b' => 2, 'c' => 'q'], 'd' => 'w']), "\n";

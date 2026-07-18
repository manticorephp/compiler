<?php

function greet(string $name, string $greeting = "hi"): string
{
    return $greeting . ", " . $name;
}

function sum(int ...$nums): int
{
    $t = 0;
    foreach ($nums as $n) { $t += $n; }
    return $t;
}

class Box
{
    public function __construct(int $w, int $h) {}
    public static function make(int $n): Box { return new Box($n, $n); }
}

echo greet("a"), "\n";              // ok (default)
echo greet("a", "b"), "\n";         // ok
echo greet(), "\n";                 // too few
echo greet("a", "b", "c"), "\n";    // too many
echo sum(1, 2, 3, 4), "\n";         // ok (variadic)
$b = new Box(1);                    // too few
$c = new Box(1, 2, 3);             // too many
echo Box::make(), "\n";            // too few

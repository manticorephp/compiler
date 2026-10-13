<?php
// A method call passing an int to a float parameter does not convert it (plain function calls do).
// issue: #228
final class K
{
    public function g(float $a, float $b): float
    {
        return $a + $b;
    }
}
function h(float $a, float $b): float
{
    return $a + $b;
}
$k = new K();
var_dump($k->g(0, 1));
var_dump(h(0, 1));
$i = 3;
var_dump($k->g($i, 1));

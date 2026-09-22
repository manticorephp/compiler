<?php

function hold(?callable $f = null)
{
    static $held;
    if ($f !== null) { $held = $f; return null; }
    return $held;
}

function seedCapturing(): void
{
    $x = "kept";
    $loc = function () use ($x) { return $x; };
    hold($loc);
}

function seedBare(): void
{
    $loc = function () { return 7; };
    hold($loc);
}

function churn(): void
{
    for ($i = 0; $i < 2048; $i++) {
        $c = function () { return 1; };
        $c();
    }
}

seedCapturing();
churn();
$g = hold();
var_dump($g());

seedBare();
churn();
$g2 = hold();
var_dump($g2());

<?php

function load(?float $avg = 0.
): string {
    return var_export($avg, true);
}

$a = 1.;
$b = 10.e2;
$c = 3_0.;
var_dump($a, $b, $c, .5, 1.5);
echo load(), "\n";
echo load(2.), "\n";
echo 7. . "x\n";

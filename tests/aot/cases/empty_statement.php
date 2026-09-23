<?php

$xs = [3, 1, 2];
for ($i = 0; $i < count($xs) && $xs[$i] !== 2; ++$i);
var_dump($i);

$n = 0;
while (++$n < 5);
var_dump($n);

foreach ($xs as $x);
var_dump($x);

if ($n > 1); else echo "no\n";
;;
{ ; }

function f(int $k): int
{
    ;
    switch ($k) {
        case 1: ;
        default: ; return $k * 2;
    }
}
var_dump(f(4));

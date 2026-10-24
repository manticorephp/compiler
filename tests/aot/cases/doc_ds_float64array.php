<?php

use Manticore\Ds\Float32Array;
use Manticore\Ds\Float64Array;

$f = new Float64Array(2);
$f[0] = 0.1;
$f[1] = 3;                      // an int is converted
echo $f[0], ' ', $f[1], ' ', $f->sum(), "\n";

$narrow = new Float32Array(1);
$narrow[0] = 0.1;               // rounded to binary32
var_dump($narrow[0]);

var_dump($f->equals(Float64Array::fromArray([0.1, 3.0])));

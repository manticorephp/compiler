<?php

use Manticore\Ds\BitArray;

$seen = new BitArray(10);       // ten bits, all false
$seen[3] = true;
$seen[7] = 1;                   // truthiness is stored
$seen[8] = '0';                 // '0' is false

var_dump($seen[3], $seen[8]);
echo $seen->sum(), "\n";        // set bits
echo $seen->indexOf(true), "\n";
echo $seen->indexOf(true, 4), "\n";

$seen->fill(false);
echo $seen->sum(), "\n";

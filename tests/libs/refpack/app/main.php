<?php
// `&...$xs` across the module boundary: the library's writes through the pack
// land in the application's variables.
$x = 'x'; $y = 'y';
\Acme\Fill::prefix('s-', $x, $y);
var_dump($x, $y);
(new \Acme\Fill())->first($x);
var_dump($x);
$a = 1; $b = 2;
\Acme\bump(10, $a, $b);
var_dump($a, $b);
// A library property promoted to cell elements, read and written from here.
$bag = new \Acme\Bag();
$bag->promote();
var_dump($bag->names);
$bag->names[] = 'c';
$bag->names[1] .= '!';
echo implode(',', $bag->names), ' ', strlen($bag->names[0]), "\n";

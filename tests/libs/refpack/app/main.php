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

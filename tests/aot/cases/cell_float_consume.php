<?php
// Consuming a float stored in a cell (mixed array element): printf %g/%e and a
// strict === against a float literal must unbox the cell by tag, not read its
// NaN-boxed carrier bits as an integer.
$a = [0.6, 0.125, 7, "s"];
printf("%.17g\n", $a[0]);
printf("%g %g\n", $a[1], $a[0]);
printf("%d\n", $a[2]);
var_dump($a[0] === 0.6);
var_dump($a[1] === 0.125);
var_dump($a[0] === 0.7);
var_dump($a[2] === 0.6);   // int cell vs float → false
var_dump($a[0] !== 0.6);

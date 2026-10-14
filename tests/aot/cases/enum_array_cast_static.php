<?php
enum E { case A; }
enum B: string { case X = 'x'; }
$a = (array) E::A;
echo count($a), "\n";
print_r($a);
print_r((array) B::X);

<?php
// Depth-3+ nested element write into a declared `array` prop. The scan walks the
// whole array-access chain to its property root, so every nesting level reads
// back as a tagged cell (is_array/var_dump/count at each depth).

class Cube { public array $c = []; }
$C = new Cube();
$C->c[0][0][0] = 1.5;
$C->c[0][0][1] = 2.5;
$C->c[0][1][0] = 9.5;
var_dump(is_array($C->c[0]));
var_dump(is_array($C->c[0][0]));
var_dump($C->c[0][0]);
echo count($C->c[0]), " ", count($C->c[0][0]), "\n";
var_dump($C->c[0][0][1]);
var_dump($C->c[0][1][0]);

// depth-4 on a mixed prop
class MCube { public mixed $c; }
$M = new MCube();
$M->c = [];
$M->c[0][0][0][0] = "deep";
var_dump(is_array($M->c[0][0][0]));
var_dump($M->c[0][0][0][0]);

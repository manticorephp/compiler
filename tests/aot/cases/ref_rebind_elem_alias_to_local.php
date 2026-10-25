<?php
// Rebinding a reference from an array element to a local (`$w = &$a[0]; $w = &$x; $w = 3`) writes a tagged cell into the local.
function t(): void { $arr = [1, 2]; $x = 0; $w = &$arr[0]; $w = &$x; $w = 3; var_dump($arr[0], $x); }
t();

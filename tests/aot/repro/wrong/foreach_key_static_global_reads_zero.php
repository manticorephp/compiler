<?php
// In a function, a foreach key bound to a static or global variable reads 0 inside the loop instead of the real key.
function st(array $a): void { static $k; foreach ($a as $k => $v) { echo var_export($k, true), ' '; } echo "\n"; }
function gl(array $a): void { global $gk; foreach ($a as $gk => $v) { echo var_export($gk, true), ' '; } echo "\n"; }
foreach ([[10, 20, 30], ['a' => 1, 'b' => 2], ['x' => 1, 5 => 2, 'y' => 3]] as $arr) { st($arr); gl($arr); }

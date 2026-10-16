<?php
// A closure called with a stdClass property as its by-reference argument emits invalid IR.
// issue: #248
$inc = function (&$v) { $v = $v . "z"; };
$props = new stdClass;
$props->p = "q";
$inc($props->p);
echo $props->p, "\n";

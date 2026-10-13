<?php
// An array stored through $GLOBALS and bound by `global` is typed int in MIR, so copying it prints its address as an int.
// issue: #87
$GLOBALS['gi2'] = [0, 1];
function g2() { global $gi2; $x = $gi2; return $x; }
var_dump(g2());

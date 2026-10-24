<?php
// `$o->p = &$v` copies the value instead of binding the property to the variable.
class O { public $p; function bind(&$v) { $this->p = &$v; } }
$o = new O; $x = "a"; $o->bind($x); $x = "b"; echo $o->p, "\n";
$y = "c"; $o->p = &$y; $y = "d"; echo $o->p, "\n";

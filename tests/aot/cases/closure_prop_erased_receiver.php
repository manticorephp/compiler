<?php
// A ?Closure property read through an erased receiver (`id($o)->f`) decodes as an int.
class C { public ?Closure $f = null; }
function id($x) { return $x; }
$o = new C;
$o->f = fn() => 7;
$g = id($o)->f;
echo $g->__invoke(), "\n";

<?php
// `$o->p = &$v` rebinds: second bind, string/array values, write via the property, clone shares.
class O { public $p; public mixed $m = null; }
$o = new O;
$a = "x"; $b = [1, 2];
$o->p = &$a;
$o->p = "via-prop";
echo $a, "\n";
$o->p = &$b;
$a = "untouched";
$b[] = 3;
echo count($o->p), " ", $a, "\n";
$o->m = &$a;
$a .= "!";
echo $o->m, "\n";
$c = clone $o;
$a = "shared";
echo $c->m, " ", $o->m, "\n";
for ($i = 0; $i < 3; $i++) { $t = "t$i"; $o->p = &$t; }
$t = "end";
echo $o->p, "\n";

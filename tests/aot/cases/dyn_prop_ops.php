<?php
// A reference bound to a dynamic property (`$o->{$e} = &$x`, `$o->n = &$x`) is read and written through
$o = new stdClass;
$e = 'n';
$x = 1;
$o->{$e} = &$x;
$x = 2;
var_dump($o->n);
$o->{'m'} = [0];
$o->{'m'}[0] = 5;
var_dump($o->m[0]);
unset($o->{$e});
var_dump(isset($o->n));
$p = new stdClass; $x = 1; $p->n = &$x; $x = 2; var_dump($p->n); $p->n = 3; var_dump($x);
function f() { $o = new stdClass; $e = "m"; $y = 1; $o->{$e} = &$y; $y = 5; var_dump($o->m); $o->m = 6; var_dump($y); $o->{$e} = 7; var_dump($y); $z = 0; $o->{$e} = &$z; $o->m = 8; var_dump($y, $z); }
f();

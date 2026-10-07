<?php
// Dynamic property ops: `$o->{e} = &$x` does not bind, dim write is lost, unset is a no-op
// issue: #34
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

<?php
$o = new stdClass;
$e = 'n';
$o->{'m'} = [0];
$o->{'m'}[0] = 5;
var_dump($o->m[0]);
$o->n = 1;
unset($o->{$e});
var_dump(isset($o->n));

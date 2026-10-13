<?php
// `$o->{e} = &$x` does not bind the dynamic property to $x
// issue: #34
$o = new stdClass;
$e = 'n';
$x = 1;
$o->{$e} = &$x;
$x = 2;
var_dump($o->n);

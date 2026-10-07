<?php
// (array) of an object with a numeric dynamic property keeps the key as the string "7"; php makes it int 7
// issue: #116
#[AllowDynamicProperties]
class Dyn { public $a = 1; }
$d = new Dyn();
$d->{'7'} = 'seven';
$a = (array)$d;
var_dump(array_keys($a), isset($a[7]));

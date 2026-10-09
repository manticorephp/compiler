<?php
// property_exists() answers false for a dynamic property of a stdClass, numeric name or not.
// issue: #120
$o = new stdClass();
$o->{'5'} = 1;
$o->{'a'} = 2;
var_dump(property_exists($o, '5'), property_exists($o, 'a'), isset($o->{'5'}));

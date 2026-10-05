<?php
// unset($o->$k[0]) with a dynamic property name is a no-op on a stdClass array property
// issue: #78
// item: 27
$k = 'p'; $q = new stdClass;
$q->$k = [1, 2];
unset($q->$k[0]);
var_dump(count($q->p));

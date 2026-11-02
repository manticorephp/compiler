<?php
// An int returned from a ?float function, or stored in a ?float property, stays int instead of converting to float.
// issue: #553
function pn(int $a): ?float { return $a; }
class N { public ?float $n = null; }
$o = new N; $o->n = 5;
var_dump(pn(5), $o->n);

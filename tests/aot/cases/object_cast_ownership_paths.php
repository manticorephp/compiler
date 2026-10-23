<?php
class H { public $o; }
function mk(array $a) { return (object)$a; }
function viaLocal(array $a) { $x = (object)$a; $y = $x; return $y->k; }
function viaScalar(int $v) { $x = (object)$v; return $x->scalar; }
function viaMixed(mixed $m) { $x = (object)$m; return $x; }
function viaProp(array $a) { $h = new H; $h->o = (object)$a; $o = $h->o; return $o->k . $h->o->k; }
function reassign() { $x = (object)['a' => 1]; $x = (object)['b' => 2]; return $x->b; }
$o = mk(['k' => 'v']);
echo $o->k, "\n";
for ($i = 0; $i < 3; $i++) {
    echo viaLocal(['k' => 'x' . $i]), viaScalar($i), viaProp(['k' => 'p' . $i]), reassign(), "\n";
}
$s = new stdClass; $s->z = 1;
var_dump(viaMixed($s) === $s, viaMixed(['q' => 5])->q, viaMixed(7)->scalar);
echo json_encode((object)['k' => 2]), "\n";

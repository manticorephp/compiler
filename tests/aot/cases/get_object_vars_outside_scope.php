<?php
// get_object_vars() called outside the class returns private and protected properties.
class C {
    public int $a = 1; private int $b = 2; protected int $c = 3;
    public function all(): array { return get_object_vars($this); }
}
class D extends C { public int $d = 4; }
var_dump(array_keys(get_object_vars(new C)));
var_dump(array_keys((new C)->all()));
var_dump(array_keys(get_object_vars(new D)));
$o = new C;
$o->dyn = 5;
echo json_encode(get_object_vars($o)), "\n";
function f(mixed $x) { return get_object_vars($x); }
echo json_encode(f(new D)), "\n";
$fn = fn($x) => get_object_vars($x);
echo json_encode($fn(new C)), "\n";

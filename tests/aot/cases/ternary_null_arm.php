<?php
// A plain ternary with a null arm and a SCALAR sibling must render null as NULL
// (not the scalar's zero) and report the right gettype — the null arm no longer
// erases into the sibling's type. Object/array siblings keep the branch type.

function probe(bool $c): void {
    $i = $c ? 5 : null;          // int|null
    var_dump($i);
    $f = $c ? 3.5 : null;        // float|null
    var_dump($f);
    $s = $c ? "hi" : null;       // string|null
    var_dump($s, gettype($s));
    $b = $c ? true : null;       // bool|null
    var_dump($b);
    var_dump($i ?? "none");
}
probe(false);
probe(true);

// null on the THEN side too
$x = (2 > 1) ? null : 9;
var_dump($x);

// scalar-null ternary flowing into an array element still boxes
$arr = ["k" => (1 > 2 ? "x" : null)];
var_dump($arr["k"] ?? "d", isset($arr["k"]));

// object/array siblings are left untouched (dispatch/subscript still work)
class Box { public int $v = 7; }
function objarm(bool $c): void {
    $o = $c ? new Box() : null;
    var_dump($o?->v ?? -1);
    $a = $c ? [1, 2] : null;
    var_dump($a[0] ?? -2);
}
objarm(false);
objarm(true);

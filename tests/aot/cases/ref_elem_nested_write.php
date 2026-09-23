<?php
// A nested write THROUGH a referenced element: `$v[0][] = 2` where `$v[0]` is
// `&$b`. The inner array was reached through the reference, so it has to go
// back through it. It used to be written back into the slot as a plain value:
// the binding was gone (`$b` never saw the append) and the buffer sat in two
// slots on one count — musl's allocator caught the double free at exit.

$b = [1];
$v = [&$b];
$v[0][] = 2;
echo "int key     : ", count($b), " ", implode(",", $b), "\n";

$a = ["a" => 1];
$ctx = ["s" => &$a];
$ctx["s"]["b"] = 2;
$a = ["q" => 1];
echo "str key     : ", count($ctx["s"]), " ", implode(",", array_keys($ctx["s"])), "\n";

$k = "s";
$m = [$k => &$a];
$m[$k]["z"] = 9;
echo "dyn key     : ", implode(",", array_keys($a)), "\n";

$d = [[1]];
$w = ["x" => &$d];
$w["x"][0][] = 5;
echo "two deep    : ", implode(",", $d[0]), "\n";

$_SESSION["a"] = 1;
$one = ["s" => &$_SESSION];
$two = ["s" => &$_SESSION];
$one["s"]["b"] = 2;
$two["s"]["c"] = 3;
echo "superglobal : ", implode(",", array_keys($_SESSION)), "\n";
$_SESSION = ["fresh" => 1];
echo "still bound : ", implode(",", array_keys($one["s"])), " ", implode(",", array_keys($two["s"])), "\n";

function f(): void
{
    global $g;
    $h = ["g" => &$g];
    $h["g"]["b"] = 2;
    $g["c"] = 3;
    echo "global      : ", implode(",", array_keys($h["g"])), "\n";
}
$g = ["a" => 1];
f();

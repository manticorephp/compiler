<?php
// $k[A] = $k[B] = v aborts: the outer store uses the array buffer fetched before the inner store reallocated it
function g(string $n): array
{
    $k = [];
    $k["x" . $n] = $k[$n] = [$n];
    return $k;
}
$r = g("a");
echo count($r), ' ', json_encode($r), "\n";

class P { public array $arr = []; }
function h(string $n): void
{
    $p = new P();
    $p->arr["x" . $n] = $p->arr[$n] = [$n, 1];
    $a = [];
    $a["y" . $n][] = $a[$n] = 5;
    $b = [];
    $b[$n . "1"] = $b[$n . "2"] = $b[$n . "3"] = 7;
    $c = ["s"];
    $c[0] .= ($c["z" . $n] = "t");
    $m = [];
    $q = $m["u" . $n] = $m[$n] = [$n];
    echo json_encode([$p->arr, $a, $b, $c, $m, $q]), "\n";
}
h("a");

<?php
// $k[A] = $k[B] = v aborts: the outer store uses the array buffer fetched before the inner store reallocated it
// issue: #190
function g(string $n): array
{
    $k = [];
    $k["x" . $n] = $k[$n] = [$n];
    return $k;
}
$r = g("a");
echo count($r), ' ', json_encode($r), "\n";

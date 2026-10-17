<?php
// Spreading an array whose int keys do not start at 0 into a variadic builtin throws ArgumentCountError.
$l = [1 => [1 => 1, 2 => 2], 2 => [2 => 5, 3 => 6]];
echo json_encode(array_intersect_key(...$l)), "\n";
function g(array $a, array ...$o) { return count($a) . '/' . count($o); }
function k(array $a, array $b = [], array ...$o) { return count($a) . '/' . count($b) . '/' . count($o); }
class S {
    public function m(int $x, int ...$r): string { return $x . ':' . implode(',', $r); }
    public static function st(int $x, int ...$r): string { return $x . ':' . implode(',', $r); }
}
$l3 = [4 => [1, 2], 6 => [3], 9 => [1, 1, 1]];
echo g(...$l), " ", g(...$l3), " ", k(...$l3), " ", k(...[7 => [1]]), "\n";
$ints = [3 => 10, 8 => 20, 2 => 30];
echo (new S)->m(...$ints), " ", S::st(...$ints), " ", S::st(...[5 => 1]), "\n";
echo json_encode(array_merge(...[3 => [1], 7 => [2]])), " ", json_encode(array_merge([0], ...[3 => [1], 7 => [2]])), "\n";
echo json_encode(array_intersect_key(...[2 => ['a' => 1, 'b' => 2], 5 => ['b' => 3]])), "\n";
echo max(...[5 => 1, 9 => 7, 2 => 3]), min(...[5 => 4, 9 => 7]), "\n";

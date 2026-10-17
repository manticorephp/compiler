<?php
enum Suit: string { case H = 'h'; case S = 's'; }
class P { public $v = 5; public $id = 'a'; public ?string $n = null; }
$rows = [['id' => 3, 'v' => 'x', 'n' => null], ['id' => 'k', 'v' => 'y'], ['v' => 'z', 'n' => 1], ['id' => 7], [5 => 'five', 'v' => 0]];
foreach ([null, 'v', 'id', 'n', 'zz', 5, 0] as $c) {
    foreach ([null, 'id', 'v', 'zz', 5] as $i) {
        echo json_encode($c), ' ', json_encode($i), ' ', json_encode(array_column($rows, $c, $i)), "\n";
    }
}
echo json_encode(array_column([], 'a')), json_encode(array_column([[]], 'a')), json_encode(array_column([[1, 2], [3, 4]], 1)), json_encode(array_column([[1, 2], [3, 4]], 1, 0)), "\n";
echo json_encode(array_column([new P, new P], 'v', 'id')), json_encode(array_column([new P, ['v' => 1, 'id' => 'b']], 'v', 'id')), json_encode(array_column([new P], 'n')), "\n";
echo json_encode(array_column(Suit::cases(), 'value')), json_encode(array_column(Suit::cases(), 'value', 'name')), "\n";
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 20000] as $n) {
    $r = [];
    for ($i = 0; $i < $n; $i++) { $r[] = ['id' => $i, 'v' => "s$i", 'f' => $i * 0.5]; }
    $a = array_column($r, 'v', 'id'); $b = array_column($r, 'f'); $c = array_column($r, null, 'id');
    echo $n, ' ', count($a), ' ', md5(serialize($a)), ' ', md5(serialize($b)), ' ', count($c), "\n";
}
$q = [["a" => [1]], ["a" => [2]]]; $x = array_column($q, "a"); $x[0][] = 9; echo json_encode($x), json_encode($q), "\n";

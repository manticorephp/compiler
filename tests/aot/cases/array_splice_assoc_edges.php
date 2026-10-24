<?php
function show(string $t, array $a, array $r): void { echo $t, ' ', json_encode($a), ' ', json_encode($r), "\n"; }
function main(): void {
    $m = ['a' => 'v1', 5 => 'v2', 'b' => 'v3', 9 => 'v4', 'c' => 'v5'];
    foreach ([[0, 0], [0, 2], [2, 1], [-2, 1], [-1, null], [5, 1], [9, 1], [1, -1], [-9, 2], [2, null]] as [$o, $l]) {
        $a = $m; $r = array_splice($a, $o, $l, ['X', 'Y']); show("arr $o,$l", $a, $r);
        $a = $m; $r = array_splice($a, $o, $l, 'S'); show("scalar $o,$l", $a, $r);
        $a = $m; $r = array_splice($a, $o, $l); show("none $o,$l", $a, $r);
        $a = $m; $r = array_splice($a, $o, $l, []); show("empty $o,$l", $a, $r);
    }
    $e = []; $r = array_splice($e, 0, 0, ['p', 'q']); show('empty-in', $e, $r);
    $e = []; $r = array_splice($e, 3, 2, 'z'); show('empty-in-off', $e, $r);
    $s = ['k1' => 'a', 'k2' => 'b', 'k3' => 'c'];
    $r = array_splice($s, 1, 1, ['k2' => 'B']); show('keyed-repl', $s, $r);
    $l = [1, 2, 3, 4, 5];
    $r = array_splice($l, 1, -1, [9]); show('list-neg-len', $l, $r);
    $r = array_splice($l, -1, 0, [7, 8]); show('list-ins', $l, $r);
}
main();
function comb(): void {
    echo json_encode(array_combine(['a', 'b', 'c'], [1, 2, 3])), json_encode(array_combine([], [])), json_encode(array_combine([1, 1], ['x', 'y'])), json_encode(array_combine(['1', 2.0, true], ['p', 'q', 'r'])), "\n";
    try { array_combine([1, 2], [1]); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
    echo json_encode(array_flip(['a', 'b', 'a'])), json_encode(array_flip([])), json_encode(array_flip([3 => 'x', 1 => 5])), "\n";
}
comb();

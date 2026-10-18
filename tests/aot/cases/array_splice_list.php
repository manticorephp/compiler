<?php
/** @param mixed[] $a */
function t(array $a, int $off, ?int $len = null, mixed $repl = []): void {
    $r = array_splice($a, $off, $len, $repl);
    echo json_encode([$a, $r]), "\n";
}
/** @return mixed[] */
function base(): array { return [10, 20, 30, 40, 50]; }
$base = base();
foreach ([[0, 0], [0, 1], [2, 2], [4, 1], [5, 1], [9, 1], [-1, null], [-2, 1], [-9, 2], [1, -1], [1, -9], [0, null], [3, null], [2, 0]] as [$o, $l]) {
    t($base, $o, $l);
    t($base, $o, $l, ["x", "y"]);
    t($base, $o, $l, "s");
    t($base, $o, $l, []);
    t($base, $o, $l, [1.5, null, true]);
}
t([], 0, null, [1]); t([], 0); t([7], 0, 1); t([7], 1, 0, [8]);
t(["a", "b", "c"], 1, 1, ["q"]);
t(["a", "b", "c", "d"], 1, 2, ["q", "r", "s"]);
t([1.5, 2.5, 3.5], 1, 1, [9.5]);
// string / sparse keys keep php's renumbering rules
t(["x" => "p", "y" => "q", "z" => "r"], 1, 1, ["n"]);
t([5 => "a", 9 => "b", 2 => "c"], 1, 1, ["n"]);
t(["a" => 1, 7 => 2, 8 => 3], 0, 1);
// repeated splices on one array, in a closure and a function
$f = function (int $n) { $a = []; for ($i = 0; $i < $n; $i++) { $a[] = $i * 3; } $s = 0; for ($i = 0; $i < 20; $i++) { $r = array_splice($a, 5, 1); $s += $r[0]; } return [$s, count($a), $a[5], $a[count($a) - 1]]; };
echo json_encode($f(100)), "\n";
/** @var mixed[] $a */
$a = base(); $a = array_merge($a, [60, 70, 80, 90, 100]);
array_splice($a, 3, 4, ["A", "B"]); echo json_encode($a), "\n";
array_splice($a, 1, 0, [[1, 2], [3]]); echo json_encode($a), "\n";
$s = ["one", "two", "three"];
$gone = array_splice($s, 1); echo json_encode([$s, $gone]), "\n";

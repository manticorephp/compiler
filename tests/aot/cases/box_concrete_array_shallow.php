<?php
final class K { public function __construct(public string $n) {} public function __destruct() { echo "~K {$this->n}\n"; } }
final class Keep { public $v; public function keep($x): void { $this->v = $x; } }
function kind($k, int $id): bool { return \is_array($k) ? \in_array($id, $k, true) : $k === $id; }
function mutate($a) { $a[] = 99; $a[0] = -1; return $a; }
function sum($a) { $s = 0; foreach ($a as $v) { $s += $v; } return $s; }
function cat($a): string { $o = ''; foreach ($a as $k => $v) { $o .= $k . '=' . $v . ';'; } return $o; }
/** @return list<int> */
function mk(int $n): array { $r = []; for ($i = 0; $i < $n; $i++) { $r[] = $i * 3; } return $r; }
$ints = [1, 2, 3];
$strs = ['a' => 'x', 'b' => 'y' . mt_rand(1, 1)];
$flts = [1.5, 2.25];
var_dump(kind($ints, 2), kind([7, 8], 8), kind(mk(5), 9), kind(mk(5), 10));
$m = mutate($ints);
echo json_encode($ints), json_encode($m), "\n";
echo sum($ints), ' ', sum(mk(4)), ' ', sum($flts), ' ', cat($strs), "\n";
$keep = new Keep();
$keep->keep($strs); $strs['c'] = 'z';
echo json_encode($keep->v), json_encode($strs), "\n";
$keep->keep(null);
echo "kept dropped\n";
$keep->keep(mk(3)); echo json_encode($keep->v), "\n";
var_dump($flts);
echo "end\n";

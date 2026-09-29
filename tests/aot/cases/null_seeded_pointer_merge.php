<?php
// A local seeded with null and given a pointer value (array, string, object, closure) in a branch
// keeps the pointer type past the if: its null rides as ptr 0. The union collapsed to unknown,
// and a mixed consumer boxed that null as int(0) (and, on older binaries, the array as an int).
class O { public int $v = 1; }
function ea(int $t): mixed { $out = null; if ($t === 7) { $out = [1]; } return $out; }
function eb(int $t): mixed { $out = null; if ($t === 7) { $out = ["k" => 2]; } return $out; }
function ec(int $t): mixed { $out = null; if ($t === 7) { $out = "s" . $t; } return $out; }
function ed(int $t): mixed { $out = null; if ($t === 7) { $out = new O; } return $out; }
/** @return int[]|null */
function ee(int $t): ?array { $out = null; if ($t === 7) { $out = [1]; } return $out; }
function ef(int $t): mixed { $out = null; if ($t === 7) { $out = [1]; } elseif ($t === 8) { $out = "x"; } return $out; }
function eg(int $t): mixed { $out = null; if ($t === 7) { $out = fn() => 1; } return $out; }
foreach ([7, 8, 3] as $t) { var_dump(ea($t), eb($t), ec($t), ed($t), ee($t), ef($t), is_null(eg($t))); $x = null; if ($t === 7) { $x = [1, 2]; } var_dump($x, $x === null, count($x ?? [])); echo json_encode(ea($t)), "\n"; }

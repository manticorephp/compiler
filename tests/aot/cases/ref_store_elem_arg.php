<?php
// An array element handed by-ref to a callee that keeps a reference shares the element, across buffer growth.
class B { public array $r = []; function bind(mixed &$v): void { $this->r[] = &$v; } }
function f(B $b): array { $a = ["k" => 1]; $b->bind($a["k"]); for ($i = 0; $i < 50; $i++) { $a["x$i"] = $i; } $a["k"] = 2; echo $b->r[0], "\n"; $b->r[0] = 3; echo $a["k"], "\n"; return $a; }
$b = new B; $a = f($b); $b->r[0] = 4; echo $a["k"], " ", count($a), "\n";
$m = json_decode('{"p":"q"}', true); $b->bind($m["p"]); $m["p"] = "z"; echo $b->r[1], "\n";
$ints = [1, 2]; $b->bind($ints[0]); $ints[0] = 5; echo $b->r[2], "\n";

<?php
// $a[$k] = $a stores the array as it was, not the array itself (Twig's $context['_parent'] = $context).
function p(array $c) { $c['_parent'] = $c; $c['x'] = 1; return count($c['_parent']) . count($c); }
echo p(['a' => 1]), "\n";
function p2(array $c) { yield 1; $c['_parent'] = $c; $c['x'] = 1; yield count($c['_parent']) . count($c); }
foreach (p2(['a' => 1]) as $v) { echo $v, ' '; } echo "\n";
function p3(array $c) { $c['_parent'] = $c; $c['loop'] = ['parent' => $c['_parent'], 'i' => 0]; ++$c['loop']['i']; $_p = $c['_parent']; unset($c['_parent'], $c['loop']); $c = array_intersect_key($c, $_p) + $_p; return json_encode($c); }
echo p3(['a' => 1, 'b' => 2]), "\n";
$a = [1]; $a[] = $a; echo json_encode($a), "\n";
$b = ['x' => 1]; $b['k'] = [$b, 'n' => $b]; $b['x'] = 2; echo json_encode($b), "\n";
$c = ['d' => ['e' => 1]]; $c['d']['self'] = $c; $c['d']['e'] = 2; echo json_encode($c), "\n";
function id(array $x): array { return $x; }
$e = [1]; $e['f'] = id($e); $e[] = 9; echo json_encode($e), "\n";
$t = ['a' => 1]; $t['b'] = true ? $t : []; $t['c'] = $t['b'] ?? null; echo json_encode($t), "\n";
class P { public array $d = ['i' => 0]; public static array $s = ['j' => 0]; public function g(): void { $this->d['me'] = $this->d; $this->d['i'] = 1; } }
$o = new P; $o->g(); echo json_encode($o->d), "\n";
P::$s['me'] = P::$s; P::$s['j'] = 1; echo json_encode(P::$s), "\n";
$h = ['n' => 1]; $h['n'] = count($h); $h['m'] = $h['n'] + 1; echo json_encode($h), "\n";
$r = []; for ($i = 0; $i < 3; $i++) { $r[$i] = $i ? $r[$i - 1] + 1 : 0; } echo json_encode($r), "\n";
$l = [1]; $v = ($l['s'] = $l); $l[] = 2; echo json_encode($v), json_encode($l), "\n";

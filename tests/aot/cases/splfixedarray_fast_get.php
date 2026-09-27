<?php
final class Toks extends SplFixedArray {}
class Loud extends SplFixedArray { public function offsetGet(mixed $index): mixed { return 'loud:' . parent::offsetGet($index); } }
function get(SplFixedArray $a, int $i): mixed { return $a[$i]; }
function sum(Toks $a): int { $t = 0; for ($i = 0; $i < $a->getSize(); $i++) { $t += $a[$i]; } return $t; }
$t = new Toks(4); for ($i = 0; $i < 4; $i++) { $t[$i] = $i * 10; }
echo sum($t), ' ', get($t, 3), "\n";
foreach ([-1, 4, 99] as $bad) {
    try { get($t, $bad); } catch (RuntimeException $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
$l = new Loud(2); $l[0] = 'a'; $l[1] = 'b';
echo get($l, 1), "\n";
$o = new Toks(2); $o[0] = new ArrayObject([1, 2]); $o[1] = 'str' . mt_rand(1, 1);
function keep(Toks $a): array { $r = []; for ($i = 0; $i < 2; $i++) { $r[] = $a[$i]; } return $r; }
$k = keep($o); unset($o);
echo count($k[0]), ' ', $k[1], "\n";

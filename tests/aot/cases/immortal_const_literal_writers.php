<?php
function lit(): array { return [3, 1, 2]; }
function slit(): array { return ['b' => 'x', 'a' => 'y']; }
function poke(int $round): string {
    $a = lit(); $a[] = $round;
    $b = lit(); $b[0] = 99;
    $c = lit(); sort($c);
    $d = lit(); next($d); $cur = current($d);
    $e = lit(); foreach ($e as &$v) { $v *= 10; } unset($v);
    $f = lit(); array_push($f, 7); array_pop($f); array_shift($f);
    $g = lit(); unset($g[1]);
    $i = slit(); $i['c'] = 'z'; ksort($i);
    $j = slit(); unset($j['b']);
    $k = lit(); array_splice($k, 1, 1);
    $l = lit(); $l2 = $l; $l2[] = 5;
    return json_encode([$a, $b, $c, $cur, $e, $f, $g, $i, $j, $k, $l, $l2]);
}
for ($round = 0; $round < 3; $round++) { echo poke($round), "\n"; }
echo json_encode([lit(), slit()]), "\n";

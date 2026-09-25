<?php

// `unset()` removes an entry IN PLACE, so it must separate a shared buffer
// first, at every level, like any other write: a by-value array parameter
// (named function or closure), a copy taken by `$m = $n`, a property snapshot,
// a nested base, and a copy a by-ref callee's unset reaches. Each line prints
// the untouched holder, then the mutated one.

function f(array $arr) { unset($arr['a']); return $arr; }
function g(array $arr): array { unset($arr[0]); return $arr; }
/** @param array<string,int> $arr */
function h(array $arr): int { unset($arr['b']); return count($arr); }
function k(array $arr): array { unset($arr['x']['p']); return $arr; }
function k2(array $arr): array { $arr['x']['p'] = 5; return $arr; }
function byref(array &$a): void { unset($a['k']); }

$q = ['a' => 1, 'b' => 2];
$r = f($q);
echo json_encode($q), json_encode($r), "\n";
$l = [1, 2, 3];
$r2 = g($l);
echo json_encode($l), json_encode($r2), "\n";
echo h($q), json_encode($q), "\n";

$n = ['x' => ['p' => 1, 'q' => 2], 'y' => 3];
$m = $n;
unset($n['x']['p']);
echo json_encode($m), json_encode($n), "\n";
echo json_encode(k($m)), json_encode($m), "\n";
echo json_encode(k2($m)), json_encode($m), "\n";

$n2 = [[1, 2], [3]];
$m2 = $n2;
unset($n2[0][1]);
echo json_encode($m2), json_encode($n2), "\n";

$deep = ['a' => ['b' => ['c' => 1, 'd' => 2]]];
$cp = $deep;
unset($deep['a']['b']['c']);
echo json_encode($cp), json_encode($deep), "\n";
$cp2 = $cp;
$cp['a']['b']['c'] = 9;
echo json_encode($cp2), json_encode($cp), "\n";

final class O { /** @var array<string, array<string,int>> */ public array $d = ['x' => ['p' => 1, 'q' => 2]]; }
$o = new O();
$keep = $o->d;
unset($o->d['x']['q']);
echo json_encode($keep), json_encode($o->d), "\n";

$cu = function (array $a): int { unset($a['k']); return count($a); };
$cs = function (array $a): int { $a['n'] = 1; return count($a); };
$src = ['k' => 1];
echo $cu($src), $cs($src), json_encode($src), "\n";

$br = ['k' => 1, 'z' => 2];
$brc = $br;
byref($br);
echo json_encode($brc), json_encode($br), "\n";

$miss = ['a' => 1];
unset($miss['zz']['y']);
echo json_encode($miss), "\n";
$mixed = ['a' => [1, 2], 'b' => 'str'];
$mc = $mixed;
unset($mixed['a'][0]);
echo json_encode($mc), json_encode($mixed), "\n";
foreach ([1, 2] as $i) {
    $it = ['x' => ['p' => $i]];
    $itc = $it;
    unset($it['x']['p']);
    echo json_encode($itc), json_encode($it), "\n";
}

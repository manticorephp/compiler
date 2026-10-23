<?php
class Holder { public $g; public array $list = []; }
$mk = function (int $n) { for ($i = 0; $i < $n; $i++) { yield $i; } return 9; };
function viaMixed(mixed $f, int $n) { $x = $f($n); $h = new Holder; $h->g = $x; $h->list[] = $x; unset($x); return $h; }
function pass(mixed $g) { $r = []; foreach ($g as $v) { $r[] = $v; } return $r; }
function early(mixed $f) { $x = $f(5); $x->current(); return 1; }
for ($k = 0; $k < 3; $k++) {
    $h = viaMixed($mk, 3);
    echo implode(',', pass($h->g)), "\n";
    $h2 = viaMixed($mk, 2);
    $arr = [$h2->g, $h2->list[0]];
    unset($h2);
    echo count($arr), $arr[0]->current(), "\n";
    echo early($mk), "\n";
}
$gen = $mk(2);
$copy = [$gen];
foreach ($gen as $v) { echo $v; }
echo $gen->getReturn(), "\n";

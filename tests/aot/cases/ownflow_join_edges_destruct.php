<?php
// A local borrowed on one path (a param) and owned on another meets at a join
// on every edge kind; each owned object must die exactly once, in php's order,
// and the borrowed one never (it outlives the calls).
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "  free {$this->n}\n"; }
}
function ifElse(D $p, bool $c): string { $x = $p; if ($c) { $x = new D('if'); } else { echo "  else\n"; } return $x->n; }
function loopBack(D $p, int $n): string { $x = $p; for ($i = 0; $i < $n; $i++) { if ($i === 1) { $x = new D("lb$i"); } } return $x->n; }
function brk(D $p): string { $x = $p; foreach ([1, 2, 3] as $v) { if ($v === 2) { $x = new D('brk'); break; } } return $x->n; }
function cont(D $p): string { $x = $p; foreach ([1, 2] as $v) { if ($v === 1) { $x = new D("c$v"); continue; } echo "  body $v\n"; } return $x->n; }
function sw(D $p, int $k): string { $x = $p; switch ($k) { case 1: $x = new D('sw1'); break; case 2: break; default: $x = new D('swd'); } return $x->n; }
function mt(D $p, int $k): string { $x = match ($k) { 1 => new D('m1'), default => $p }; return $x->n; }
function ct(D $p, bool $t): string { $x = $p; try { if ($t) { throw new RuntimeException('e'); } $x = new D('try'); } catch (RuntimeException $e) { echo "  caught\n"; } return $x->n; }
$p = new D('param');
foreach ([true, false] as $c) { echo 'ifElse ', ifElse($p, $c), "\n"; }
foreach ([0, 3] as $n) { echo 'loopBack ', loopBack($p, $n), "\n"; }
echo 'brk ', brk($p), "\n";
echo 'cont ', cont($p), "\n";
foreach ([1, 2, 3] as $k) { echo 'sw ', sw($p, $k), "\n"; }
foreach ([1, 2] as $k) { echo 'mt ', mt($p, $k), "\n"; }
foreach ([true, false] as $t) { echo 'ct ', ct($p, $t), "\n"; }
echo "end\n";

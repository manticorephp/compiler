<?php
// An erased (bare `array`) local rebound to another kind inside an EXPRESSION
// arm — a ternary arm, the right side of `&&` / `||` / `and` / `or`, `?:`,
// `??`, `??=`, a match arm — meets the path that skipped the arm: the local
// becomes a cell, every read sees the kind that is really there, and the
// erased array is released when it is replaced.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function erase(array $a): array { return $a; }
function kind(mixed $v): string { return is_string($v) ? 's' : (is_array($v) ? 'a' : (is_int($v) ? 'i' : '?')); }
function tern(bool $c): void { $r = erase(['k' => new D('tern')]); $c ? ($r = 'x') : null; echo "tern ", kind($r), "\n"; }
function tern2(bool $c): void { $r = erase(['k' => new D('tern2')]); $c ? null : ($r = 7); echo "tern2 ", kind($r), "\n"; }
function andd(bool $c): void { $r = erase(['k' => new D('and')]); $c && ($r = 'x'); echo "and ", kind($r), "\n"; }
function orr(bool $c): void { $r = erase(['k' => new D('or')]); $c || ($r = 5); echo "or ", kind($r), "\n"; }
function kwand(bool $c): void { $r = erase(['k' => new D('kwand')]); $c and ($r = 'k'); echo "kwand ", kind($r), "\n"; }
function kwor(bool $c): void { $r = erase(['k' => new D('kwor')]); $c or ($r = 'k'); echo "kwor ", kind($r), "\n"; }
function short(int $k): void { $r = erase(['k' => new D('short' . $k)]); $k ?: ($r = 'z'); echo "short ", kind($r), "\n"; }
function coal(?int $k): void { $r = erase(['k' => new D('coal')]); $k ?? ($r = 'n'); echo "coal ", kind($r), "\n"; }
function coalAssign(bool $c): void
{
    $r = erase(['k' => new D('coalAssign')]);
    $x = $c ? null : 1;
    $x ??= ($r = 's');
    echo "coalAssign ", kind($r), " ", $x, "\n";
}
function mt(int $k): void
{
    $r = erase(['k' => new D('mt' . $k)]);
    match ($k) { 1 => $r = 'one', 2 => $r = 2, default => null };
    echo "mt ", kind($r), "\n";
}
function guard(mixed $o): void
{
    $a = erase([new D('guard')]);
    $n = $o instanceof D && ($a = $o->n) !== '' ? 1 : 0;
    echo "guard ", $n, " ", kind($a), "\n";
}
foreach ([true, false] as $c) {
    tern($c); tern2($c); andd($c); orr($c); kwand($c); kwor($c); coalAssign($c);
}
short(0); short(1); coal(null); coal(1);
mt(1); mt(2); mt(3);
$g = new D('o'); guard($g); guard(1); $g = null;
echo "done\n";

<?php
// Every source a storable reference can be taken to keeps a storage that
// outlives its frame or its object: a by-value parameter and a property are
// promoted into a box, and `$a = &$b` makes both names one reference.
class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~{$this->n}\n"; }
}

function param($x) { return [&$x]; }
function typedParam(int $x) { $r = [&$x]; $x = $x + 1; return $r; }
function noise(int $n): int { $a = [$n, $n * 2, $n * 3]; return array_sum($a); }
$a = param('hello'); noise(7); $b = typedParam(41); noise(9);
var_dump($a[0], $b[0]);
$a[0] .= '!';
var_dump($a);

class P { public int $x = 0; }
function outlive(): array { $p = new P(); $p->x = 5; return [&$p->x]; }
$r = outlive();
echo "noise ", str_repeat('q', 3), "\n";
$r[0] += 1;
var_dump($r[0]);
function share(): void { $p = new P(); $a = [&$p->x]; $b = [&$p->x]; $a[0] = 7; echo $p->x, ' ', $b[0], "\n"; $p->x = 9; echo $a[0], "\n"; }
share();
class Q { public int $n = 1; }
function cloned(): void { $q = new Q(); $keep = [&$q->n]; $c = clone $q; $c->n = 42; echo $q->n, ' ', $keep[0], "\n"; }
cloned();

function rebind(): void
{
    $o = new D('rb');
    $x = new D('x');
    $r = [&$o];
    $o = &$x;
    echo "rebound\n";
    $o = new D('y');
    echo "y in\n";
    unset($r);
    echo "r gone\n";
}
rebind();
echo "after rebind\n";
function rebindUnset(): void { $o = new D('solo'); $x = 1; $r = [&$o]; unset($r); $o = &$x; echo "rebound\n"; unset($o); $o = 5; echo $o, ' ', $x, "\n"; }
rebindUnset();
function rebindInt(): void { $o = 1; $x = 2; $r = [&$o]; $o = &$x; $o = 5; echo $r[0], ' ', $x, "\n"; }
rebindInt();
echo "done\n";

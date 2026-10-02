<?php
// A local that owns an erased-array call result and is also re-bound to an
// erased array param (or to another such local) stays managed: the call's +1
// is dropped on the rebind, the alias takes its own +1, and nothing leaks.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function erase(array $a): array { return $a; }
function rebind(array $p): void
{
    $r = erase(['k' => new D('fresh')]);
    echo "got ", $r['k']->n, "\n";
    $r = $p;
    echo "now ", $r['k']->n, "\n";
    $s = $r;
    unset($r);
    echo "alias ", $s['k']->n, "\n";
}
function pick(array $p, bool $c): array
{
    $r = erase(['k' => new D($c ? 'kept' : 'dropped')]);
    if ($c) { $r = $p; }
    return $r;
}
$p = erase(['k' => new D('param')]);
for ($i = 0; $i < 2; $i++) {
    rebind($p);
    echo "--\n";
    $x = pick($p, $i === 0);
    echo "picked ", $x['k']->n, "\n";
    unset($x);
    echo "--\n";
}
unset($p);
echo "done\n";

<?php
// A local holding an erased-array call result and re-bound to a concrete array
// literal — conditionally or not — keeps one release class: the result is
// released on the rebind and at the end, and a conditional rebind compiles.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function erase(array $a): array { return $a; }
function cond(bool $c): void
{
    $r = erase(['k' => new D($c ? 'e1' : 'e2')]);
    if ($c) { $r = ['k' => new D('lit')]; }
    echo count($r), " ", $r['k']->n, "\n";
}
function straight(): void
{
    $r = erase(['k' => new D('e')]);
    $r = ['k' => new D('lit2')];
    echo $r['k']->n, "\n";
}
cond(true);
cond(false);
straight();
echo "done\n";

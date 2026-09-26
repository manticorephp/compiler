<?php
// A conditional return whose result type is erased (a bare `array` arm): the
// function hands back +1 on every path — the Own arm moves, the Borrow arm is
// retained — so the caller's local owns the result and `unset()` releases it.
// The __destruct lines show both, typed and bare declared return, both arms.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function mk(string $n): array { return ['k' => new D($n)]; }

function bareMix(array $src, bool $c): array
{
    $base = ['k' => new D('base')];
    return $c ? $src : $base;
}
/** @return array<string, D> */
function typedMix(array $src, bool $c): array
{
    $base = ['k' => new D('tbase')];
    return $c ? $src : $base;
}
function bareOwn(bool $c): array
{
    $x = mk('x');
    $y = mk('y');
    return $c ? $x : $y;
}
/** @return array<string, D> */
function typedOwn(bool $c): array
{
    $x = mk('tx');
    $y = mk('ty');
    return $c ? $x : $y;
}
function bareParam(array $src): array
{
    return $src;
}

$src = ['k' => new D('src')];
foreach ([true, false] as $c) {
    echo "-- bareMix ", $c ? 'src' : 'base', "\n";
    $rb = bareMix($src, $c);
    echo "got ", $rb['k']->n, "\n";
    unset($rb);
    echo "-- typedMix ", $c ? 'src' : 'base', "\n";
    $r = typedMix($src, $c);
    echo "got ", $r['k']->n, "\n";
    unset($r);
    echo "-- bareOwn ", $c ? 'x' : 'y', "\n";
    $ro = bareOwn($c);
    echo "got ", $ro['k']->n, "\n";
    unset($ro);
    echo "-- typedOwn ", $c ? 'x' : 'y', "\n";
    $r = typedOwn($c);
    echo "got ", $r['k']->n, "\n";
    unset($r);
}
echo "-- bareParam\n";
for ($i = 0; $i < 3; $i++) {
    $rp = bareParam($src);
    echo "got ", $rp['k']->n, "\n";
    unset($rp);
}
echo "-- end\n";
unset($src);
echo "done\n";

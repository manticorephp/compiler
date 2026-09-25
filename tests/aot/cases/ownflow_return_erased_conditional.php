<?php
// A conditional return whose result type is erased (a bare `array` arm): the
// arm that is returned moves to the caller, the arm that is not is dropped at
// the return — the __destruct lines show both, typed and bare declared return.
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

$src = ['k' => new D('src')];
foreach ([true, false] as $c) {
    // Only the untaken-owned-arm half here: a caller holding a bare-array
    // result in an erased local never releases it (pre-existing, both modes).
    if ($c) {
        echo "-- bareMix src\n";
        $r = bareMix($src, $c);
        echo "got ", $r['k']->n, "\n";
        unset($r);
    }
    echo "-- typedMix ", $c ? 'src' : 'base', "\n";
    $r = typedMix($src, $c);
    echo "got ", $r['k']->n, "\n";
    unset($r);
    echo "-- bareOwn ", $c ? 'x' : 'y', "\n";
    $r = bareOwn($c);
    echo "got ", $r['k']->n, "\n";
    unset($r);
    echo "-- typedOwn ", $c ? 'x' : 'y', "\n";
    $r = typedOwn($c);
    echo "got ", $r['k']->n, "\n";
    unset($r);
}
echo "-- end\n";
unset($src);
echo "done\n";

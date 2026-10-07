<?php
// An erased (bare `array`) value meeting a string, a scalar or a null at a
// VALUE join — ternary arms, match arms, the returns of a `mixed` function —
// or a null at a LOCAL join (if, switch, catch, a loop that may not run):
// the result is a cell (the `?array` sources keep the value erased), so every kind test and every null test (`is_null`,
// `=== null`, `isset`, `??`, `gettype`) sees what is really there, and the
// erased array is released on the path that drops it.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function erase(array $a): array { return $a; }
function kind(mixed $v): string
{
    return is_string($v) ? 's' : (is_array($v) ? 'a' : (is_int($v) ? 'i' : (is_null($v) ? 'n' : '?')));
}
function nulls(mixed $r): string
{
    return (is_null($r) ? 'N' : 'S') . ($r === null ? 'N' : 'S') . (isset($r) ? 'S' : 'N')
        . (($r ?? 'd') === 'd' ? 'N' : 'S');
}
function tern(bool $c): void { $v = $c ? erase(['k' => new D('tern')]) : 'str'; echo "tern ", kind($v), "\n"; }
function ternNull(bool $c): void { $v = $c ? erase([new D('ternNull')]) : null; echo "ternNull ", kind($v), " ", nulls($v), "\n"; }
function mt(int $k): void
{
    $v = match ($k) { 1 => erase([new D('mt')]), 2 => 7, default => 'str' };
    echo "mt ", kind($v), "\n";
}
function mtNull(int $k): void { $v = match ($k) { 1 => erase([new D('mtNull')]), default => null }; echo "mtNull ", nulls($v), "\n"; }
function ret(bool $c): mixed { if ($c) { return erase([new D('ret')]); } return 'str'; }
function ifNull(bool $c): void
{
    $r = null;
    if ($c) { $r = erase([new D('ifNull')]); }
    echo "ifNull ", nulls($r), " ", kind($r), "\n";
}
function swNull(int $k, ?array $src): void
{
    $r = null;
    switch ($k) { case 1: $r = erase($src); break; }
    echo "swNull ", nulls($r), "\n";
}
function catchNull(bool $t, ?array $src): void
{
    $r = erase($src);
    try {
        if ($t) { throw new RuntimeException('x'); }
    } catch (RuntimeException $e) {
        $r = null;
    }
    echo "catchNull ", nulls($r), "\n";
}
function loopNull(int $n, ?array $src): void
{
    $r = null;
    for ($i = 0; $i < $n; $i++) { $r = erase($src); }
    echo "loopNull ", nulls($r), "\n";
}
function viaNullable(?array $a): void { $v = $a === null ? 'str' : erase($a); echo "viaNullable ", kind($v), "\n"; }
foreach ([true, false] as $c) { tern($c); ternNull($c); ifNull($c); catchNull($c, [new D('catchNull')]); }
mt(1); mt(2); mt(3); mtNull(1); mtNull(2);
echo kind(ret(true)), kind(ret(false)), "\n";
swNull(1, [new D('swNull')]); swNull(2, null); loopNull(2, [new D('loop')]); loopNull(0, [new D('noloop')]);
viaNullable(null); viaNullable([1]);
echo "done\n";

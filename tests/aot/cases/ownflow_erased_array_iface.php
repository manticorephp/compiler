<?php
// A declared bare `array` return is +1 whatever body the call reaches: through
// an interface or abstract receiver, a `?array` hint, and a first-class
// callable forwarding it — the caller owns the result and releases it.
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
interface I { public function get(array $a): array; }
abstract class Base { abstract public function get(array $a): array; }
final class Impl implements I
{
    public function get(array $a): array { return $a; }
}
final class Sub extends Base
{
    public function get(array $a): array { return ['k' => new D('sub-' . $a['k']->n)]; }
}
final class St
{
    public static function make(array $a): array { return $a; }
    public function inst(array $a): array { return ['k' => new D('inst')]; }
}
function erase(array $a): array { return $a; }
function maybe(?array $a): ?array { return $a; }
function viaI(I $i, array $q): void { $x = $i->get($q); echo "i ", $x['k']->n, "\n"; }
function viaBase(Base $b, array $q): void { $x = $b->get($q); echo "b ", $x['k']->n, "\n"; }

$q = erase(['k' => new D('q')]);
for ($n = 0; $n < 2; $n++) {
    viaI(new Impl(), $q);
    viaBase(new Sub(), $q);
    $m = maybe($q);
    echo "m ", $m['k']->n, "\n";
    unset($m);
    $f = erase(...);
    $s = St::make(...);
    $o = (new St())->inst(...);
    $r1 = $f($q);
    $r2 = $s($q);
    $r3 = $o($q);
    echo "fcc ", $r1['k']->n, $r2['k']->n, $r3['k']->n, "\n";
    unset($r1, $r2, $r3, $f, $s, $o);
    echo "--\n";
}
unset($q);
echo "done\n";

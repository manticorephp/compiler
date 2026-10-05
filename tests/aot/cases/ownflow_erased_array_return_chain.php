<?php
// A declared bare `array` return hands back +1 on EVERY path — free function,
// method, static method or closure — so a chain of forwarders passing an erased
// array through, and the caller's local that finally owns it, agree. A +0
// forwarded as +1 freed the caller's array (SIGSEGV).
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
final class Box
{
    public static function sid(array $a): array { return $a; }
    public function id(array $a): array { return $a; }
    public function pick(array $a, bool $c): array
    {
        $own = ['k' => new D('own')];
        return $c ? $a : $own;
    }
}
function fwd(array $a): array { return $a; }
function fwd2(array $a): array { return fwd($a); }
function fwd3(array $a): array { return Box::sid($a); }
function fwd4(array $a): array { return (new Box())->id($a); }
function user(array $a): array
{
    $f = function (array $x): array { return $x; };
    return $f(fwd4(fwd3(fwd2($a))));
}
function erase(array $a): array { return $a; }

$q = erase(['k' => new D('q')]);
for ($i = 0; $i < 4; $i++) {
    fwd3($q);
    $z = fwd3($q);
    $y = user($q);
    $w = (new Box())->pick($q, $i % 2 === 0);
    $s = Box::sid($q);
    $v = array_values($q);
    echo $i, " ", $z['k']->n, $y['k']->n, $w['k']->n, $s['k']->n, $v[0]->n, "\n";
    unset($z, $y, $w, $s, $v);
    echo "unset\n";
}
unset($q);
echo "done\n";

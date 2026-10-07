<?php
// regression guard: passes without the flag
// `return $c ? $x : $y` with both locals owned: the taken arm goes to the
// caller, the other dies at the return — once each, in php's order. Covered
// (one class) and not covered (two classes, a union result) alike.
class D
{
    public function __construct(public string $n) { echo "new {$this->n}\n"; }
    public function __destruct() { echo "free {$this->n}\n"; }
}
final class E extends D {}
final class F { public function __construct(public string $n) { echo "new {$this->n}\n"; } public function __destruct() { echo "free {$this->n}\n"; } }
function pick(bool $c): D
{
    $x = new D('x');
    $y = new D('y');
    return $c ? $x : $y;
}
function pickU(bool $c): D|F
{
    $x = new D('ux');
    $y = new F('uy');
    return $c ? $x : $y;
}
function direct(): D
{
    $a = new D('a');
    $b = new D('b');
    return $a;
}
$p = pick(true); echo "got {$p->n}\n"; $p = null;
$q = pick(false); echo "got {$q->n}\n"; $q = null;
$u = pickU(true); echo "got {$u->n}\n"; $u = null;
$w = pickU(false); echo "got {$w->n}\n"; $w = null;
$r = direct(); echo "got {$r->n}\n"; $r = null;
echo "end\n";

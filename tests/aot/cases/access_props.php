<?php
class A {
    private int $p = 1;
    protected int $q = 2;
    public int $r = 3;
    public function readP(): int { return $this->p; }
    public function writeP(int $v): void { $this->p = $v; }
    public function peek(A $o): int { return $o->p + $o->q; }
    public function viaClosure(): Closure { return function () { return $this->p . '/' . $this->q; }; }
    public function viaArrow(): Closure { return fn() => $this->p; }
    public static function sp(A $o): int { return $o->p; }
}
class B extends A {
    public function readQ(): int { return $this->q; }
    public function writeQ(int $v): void { $this->q = $v; }
    public function sib(B $o): int { return $o->q; }
}
class M {
    private int $hidden = 5;
    protected int $prot = 6;
    public function __get($n) { return strlen($n); }
    public function __set($n, $v) { echo "set:$n=$v\n"; }
}
function outside(A $a): void {
    try { echo $a->p, "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
$a = new A; $b = new B;
try { echo $a->p, "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo $a->q, "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { $a->p = 9; echo "no\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { $b->q = 9; echo "no\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
outside($a);
echo $a->r, "\n";
echo $a->readP(), "\n";
$a->writeP(7); echo $a->readP(), "\n";
echo $a->peek(new A), "\n";
echo $b->readQ(), "\n";
$b->writeQ(8); echo $b->readQ(), "\n";
echo $b->sib(new B), "\n";
echo $a->viaClosure()(), "\n";
echo $a->viaArrow()(), "\n";
echo A::sp($a), "\n";
$peek = function () { return $this->p; };
echo Closure::bind($peek, $a, A::class)(), "\n";
echo $peek->bindTo($a, A::class)(), "\n";
echo $peek->call($a), "\n";
$rp = new ReflectionProperty(A::class, 'p');
echo $rp->getValue($a), "\n";
$m = new M;
echo $m->hidden, "\n";
echo $m->prot, "\n";
$m->hidden = 1;
$m->prot = 2;
echo $m->nothing, "\n";

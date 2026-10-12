<?php
final class D { public function __construct(public int $n = 0) {} public function __destruct() { echo "d", $this->n, "\n"; } }
final class B {
    public int $i = 1; public float $f = 1.5; public bool $b = false;
    public string $s = "x"; public ?D $o = null; public array $a = [1];
}
final class Sink {
    public function fi(int &$x): void { $x += 41; }
    public function ff(float &$x): void { $x *= 2; }
    public function fb(bool &$x): void { $x = !$x; }
    public function fs(string &$x): void { $x = $x . "abc"; }
    public function fo(?D &$x): void { $x = new D(7); }
    public function fn(string &$x): void { }
    public function fa(array &$x): void { $x[] = 2; }
}
function feed(Sink $k, mixed $o): void {
    $k->fi($o->i); $k->ff($o->f); $k->fb($o->b); $k->fs($o->s); $k->fs($o->s);
    $k->fo($o->o); $k->fo($o->o); $k->fn($o->s); $k->fa($o->a);
}
$c = new B();
feed(new Sink(), $c);
echo $c->i, " ", $c->f, " ", var_export($c->b, true), " ", $c->s, " ", $c->o->n, " ", count($c->a), "\n";
$c->o = null;
echo "end\n";

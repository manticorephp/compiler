<?php
// Property hooks run whatever the receiver's static type: exact class, a union, an erased (untyped / mixed) slot.
class A
{
    public int $x { get => 42; set { throw new Error('A set ' . $value); } }
    public string $s { get => 'str' . $this->n; }
    public float $f { get => 1.5; }
    public ?string $ns { get => $this->n > 1 ? null : 'one'; }
    public int $n = 1;
    public int $backed = 3 {
        get => $this->backed * 10;
        set(int $v) { $this->backed = $v + 1; }
    }
}
class B extends A {}
class C
{
    public int $x = 5;
    public string $s = 'c';
    public float $f = 2.5;
    public ?string $ns = 'cc';
    public int $backed = 7;
}
class H
{
    public $o;
    public mixed $p;
}
function make(): A|false { return new A(); }
function either(int $i): A|C { return $i === 0 ? new A() : new C(); }
function viaMixed(mixed $o): string { return $o->x . ' ' . $o->s . ' ' . $o->f . ' ' . var_export($o->ns, true) . ' ' . $o->backed; }
function attempt(callable $f): void
{
    try { $f(); echo "no error\n"; } catch (Error $e) { echo $e->getMessage(), "\n"; }
}

$a = make();
echo $a->x, ' ', $a->s, ' ', $a->f, ' ', var_export($a->ns, true), ' ', $a->backed, "\n";
attempt(function () { $a = make(); $a->x = 3; });
$a->backed = 4;
echo $a->backed, "\n";

foreach ([0, 1] as $i) {
    $u = either($i);
    echo get_class($u), ': ', $u->x, ' ', $u->s, ' ', $u->f, ' ', var_export($u->ns, true), ' ', $u->backed, "\n";
    attempt(function () use ($i) { $u = either($i); $u->x = 9; });
}

$h = new H();
$h->o = new B();
$h->p = new A();
$h->p->n = 2;
echo $h->o->x, ' ', $h->o->s, ' ', $h->o->f, ' ', var_export($h->o->ns, true), ' ', $h->o->backed, "\n";
echo $h->p->x, ' ', $h->p->s, ' ', var_export($h->p->ns, true), "\n";
attempt(function () use ($h) { $h->o->x = 1; });
attempt(function () use ($h) { $h->p->x = 2; });
$h->o->backed = 10;
echo $h->o->backed, "\n";
echo viaMixed(new A()), "\n", viaMixed(new B()), "\n", viaMixed(new C()), "\n";
$list = [new A(), new C(), new B()];
foreach ($list as $o) { echo $o->x, ' ', $o->s, "\n"; }

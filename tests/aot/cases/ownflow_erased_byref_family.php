<?php
// A by-reference `get()` on an UNRELATED class does not stop A::get(): array
// from handing its caller the +1 it owns; a by-ref override in A's own family
// does (that body hands back an address).
final class D
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
}
function erase(array $a): array { return $a; }
class A { public function get(array $a): array { return $a; } }
final class B
{
    private array $v = [];
    public function &get(): array { return $this->v; }
}
$b = new B();
$ref = &$b->get();
$x = (new A())->get(erase(['k' => new D('a')]));
echo $x['k']->n, "\n";
unset($x);
echo "after\n";

<?php
// Untyped array param spread into a dynamic method call through a trait.
trait Fwd {
    protected function fwd($object, $method, $parameters) {
        return $object->{$method}(...$parameters);
    }
}
class A { use Fwd;
    public function add($a, $b) { return $a + $b; }
    public function many(...$xs) { return count($xs); }
    protected function hidden($x) { return "hidden$x"; }
    public function go($o, $m, $p) { return $this->fwd($o, $m, $p); }
    public function self($m, $p) { return $this->fwd($this, $m, $p); }
}
class B { use Fwd;
    public function __call($n, $a) { return "magic:$n:" . count($a); }
    public function go($o, $m, $p) { return $this->fwd($o, $m, $p); }
}
$a = new A; $b = new B;
echo $a->go(new A, 'add', [1, 2]), "\n";
echo $a->go(new A, 'many', [1, 2, 3, 4]), "\n";
echo $a->go(new A, 'many', []), "\n";
echo $a->self('hidden', [7]), "\n";
echo $b->go(new B, 'whatever', [1, 2]), "\n";
echo $b->go($a, 'add', [5, 6]), "\n";
try { $a->go(new A, 'hidden', [1]); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { $a->go(new A, 'nope', []); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }

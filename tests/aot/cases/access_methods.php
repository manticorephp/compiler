<?php
class A {
    private function priv() { return 'priv'; }
    protected function prot() { return 'prot'; }
    public function pub() { return $this->priv() . $this->prot(); }
    public function other(A $o) { return $o->priv() . $o->prot(); }
    public function viaClosure() { return function () { return $this->priv(); }; }
    public static function make() { $o = new static; return $o->priv(); }
}
class B extends A {
    public function callProt() { return $this->prot(); }
    public function callParent() { return parent::prot(); }
}
class C {
    private function hidden() { return 'hidden'; }
    public function __call($n, $a) { return "call:$n"; }
}
trait T { private function tp() { return 'tp'; } public function useTp() { return $this->tp(); } }
class UT { use T; }
function outside(A $a) {
    try { echo $a->priv(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
$a = new A; $b = new B;
try { echo $a->priv(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo $a->prot(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { echo $b->prot(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
outside($a);
echo $a->pub(), "\n";
echo $a->other(new A), "\n";
echo $a->viaClosure()(), "\n";
echo A::make(), "\n";
echo $b->callProt(), "\n";
echo $b->callParent(), "\n";
$c = new C;
echo $c->hidden(), "\n";
echo (new UT)->useTp(), "\n";
try { echo (new UT)->tp(), "\n"; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$f = function () { return $this->priv(); };
echo Closure::bind($f, $a, A::class)(), "\n";

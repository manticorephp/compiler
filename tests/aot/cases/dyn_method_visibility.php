<?php
class A {
    public function pub(int $x = 1): string { return "A::pub($x)"; }
    private function priv(int $x = 1): string { return "A::priv($x)"; }
    protected function prot(int $x = 1): string { return "A::prot($x)"; }
    public function callSelf(string $m, array $args): string { return $this->$m(...$args); }
    public function callOther(A $o, string $m): string { return $o->$m(7); }
}
class B extends A {
    public function callUp(string $m): string { return $this->$m(5); }
}
class M {
    private function hidden(): string { return 'M::hidden'; }
    public function __call(string $n, array $a): string { return "M::__call($n," . count($a) . ')'; }
}
class Other {
    public function poke(object $o, string $m): mixed { return $o->$m(3); }
}
function tryCall(callable $f): void {
    try { echo $f(), "\n"; } catch (\Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
}
$a = new A(); $b = new B(); $m = new M(); $other = new Other();
$names = ['pub', 'priv', 'prot', 'nope'];
foreach ($names as $n) {
    tryCall(fn() => $a->$n(2));
    tryCall(fn() => $a->$n(...[4]));
    tryCall(fn() => $a->callSelf($n, [6]));
    tryCall(fn() => $b->callUp($n));
    tryCall(fn() => $a->callOther($b, $n));
    tryCall(fn() => $other->poke($a, $n));
}
foreach (['hidden', 'missing'] as $n) {
    tryCall(fn() => $m->$n(1, 2));
    tryCall(fn() => $other->poke($m, $n));
}

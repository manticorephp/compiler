<?php
// $o->{$name}(...$args) on erased / union receivers: per-name shared arm helpers.
class A {
    public function m0($a) { return "A.m0($a)"; }
    public function m1($a, $b = 'd') { return "A.m1($a,$b)"; }
    public function m2() { return "A.m2"; }
    public function m3($a, $b) { return $a + $b; }
    public function m4(...$r) { return "A.m4:" . implode(',', $r); }
    public function m5(int $x) { return $x * 2; }
    public function m6($a) { return "A.m6($a)"; }
    public function m7($a) { return "A.m7($a)"; }
    public function m8($a) { return "A.m8($a)"; }
    public function m9($a) { return "A.m9($a)"; }
    public function m10($a) { return "A.m10($a)"; }
    public function m11($a) { return "A.m11($a)"; }
    public function m12($a) { return "A.m12($a)"; }
    public function m13($a) { return "A.m13($a)"; }
    public function m14($a) { return "A.m14($a)"; }
    public function m15($a) { return "A.m15($a)"; }
    public function m16($a) { return "A.m16($a)"; }
    public function m17($a) { return "A.m17($a)"; }
    public function m18($a) { return "A.m18($a)"; }
    public function m19($a) { return "A.m19($a)"; }
}
class B extends A {
    public function m0($a) { return "B.m0($a)"; }
    public function m2() { return "B.m2"; }
}
class C extends B {
    public function m0($a) { return "C.m0($a)"; }
    public function m1($a, $b = 'cd') { return "C.m1($a,$b)"; }
}
class M {
    public function __call($n, $a) { return "M.__call($n," . implode(',', $a) . ")"; }
    public function m0($a) { return "M.m0($a)"; }
}
function call($o, string $n, array $args) {
    try {
        return $o->{$n}(...$args);
    } catch (\Throwable $e) {
        return get_class($e) . ($e instanceof ArgumentCountError ? '' : ': ' . $e->getMessage());
    }
}
$objs = [new A, new B, new C, new M];
foreach ($objs as $o) {
    foreach ([['m0', [1]], ['m1', [1]], ['m1', [1, 2]], ['m2', []], ['m3', [1, 2]], ['m3', [1]],
              ['m4', [1, 2, 3]], ['m5', [21]], ['m19', ['z']], ['nope', [1]]] as [$n, $a]) {
        echo get_class($o), '->', $n, ': ', var_export(call($o, $n, $a), true), "\n";
    }
}
function viaUntyped($o, $n) { try { return $o->$n(...[5]); } catch (\Throwable $e) { return get_class($e); } }
echo viaUntyped(new A, 'm0'), "\n";
echo viaUntyped(new C, 'm0'), "\n";

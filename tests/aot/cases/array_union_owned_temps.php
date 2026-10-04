<?php
class A { public function __construct(public string $t) {} public function __destruct() { echo "~{$this->t}\n"; } }
final class B extends A {}
final class C { public function __construct(public string $t) {} public function __destruct() { echo "~C{$this->t}\n"; } }
function mk(int $i): array { return $i > 0 ? [new A('a'), new C('c')] : []; }
/** @param array<int, A|C> $v */
function mut(array $v): int { $v[] = new A('m'); return count($v); }
function run(): void {
    $v = [new A('a1'), new C('c1')];
    $w = $v + [5 => new A('x')];
    echo count($w), "\n";
    $w = null;
    echo "w-dropped\n";
    echo mut($v), "\n";
    echo "mutated\n";
    echo $v[0]->t, $v[1]->t, "\n";
    $v = null;
    echo "v-dropped\n";
}
run();
echo "end\n";
function erasedK(): array { return [new A('e1'), new A('e2')]; }
function nested(): void {
    $d = [1 => new A('u1')] + erasedK() + [7 => [new A('u2')]];
    echo count($d), "\n";
    $d = null;
    echo "d\n";
}
nested();
echo "end2\n";

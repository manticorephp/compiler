<?php
class A { public function __construct(public string $t) {} public function __destruct() { echo "~A{$this->t}\n"; } }
final class C { public function __construct(public string $t) {} public function __destruct() { echo "~C{$this->t}\n"; } }
function pick(int $i): A|C { return $i % 2 === 0 ? new A((string)$i) : new C((string)$i); }
/** @param list<A|C> $v */
function mut(array $v): int { $v[] = pick(9); return count($v); }
function run(int $n): void {
    $v = [pick($n), pick($n + 1)];
    $u = [$n > 0 ? new A('t') : new C('f')];
    $w = $v;
    $w[] = pick(4);
    echo count($w), "\n";
    $w = null;
    echo "w\n";
    echo mut($v), mut($u), "\n";
    echo "m\n";
    $x = $v + [3 => pick(6)];
    $x = null;
    echo "x\n";
    $y = [...$v, ...$u];
    $v = null;
    $u = null;
    echo "v\n";
    $y = null;
    echo "y\n";
}
run(2);
echo "end\n";

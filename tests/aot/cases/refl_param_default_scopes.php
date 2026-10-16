<?php
interface HasC { const K = 'ik'; public function m($a = self::K); }
trait Tr {
    public static function st($x = self::Q, $y = PHP_INT_MAX, ...$rest) {}
    public function mk($o = new Dep(2), $d = Suit::Hearts) {}
    abstract public function ab($v = [1, 2 => 'b']);
}
trait Tr2 { public function twice(&$r = null, $n = 1 + 2 * 3) {} }
enum Suit { case Hearts; }
class Dep { public function __construct(public int $n = 0) {} }
class A implements HasC {
    use Tr, Tr2 { twice as thrice; }
    const Q = 'qa';
    public function __construct(public int $p = self::Z, protected ?array $arr = [self::Q]) {}
    const Z = 9;
    public function m($a = self::K) {}
    public function ab($v = [1, 2 => 'b']) {}
}
class B extends A { const Q = 'qb'; }
class Child extends Dep { public function c($q = parent::class, $w = new self) {} }
function show(string $cls, string $m): void {
    foreach ((new ReflectionMethod($cls, $m))->getParameters() as $p) {
        echo $cls, '::', $m, ' $', $p->getName(), ' = ';
        if (!$p->isDefaultValueAvailable()) { echo "-\n"; continue; }
        $v = $p->getDefaultValue();
        echo is_object($v) ? get_class($v) . ($v instanceof Dep ? $v->n : '') : json_encode($v), "\n";
    }
}
foreach ([["Child", "c"], ["A", "st"], ['B', 'st'], ['A', 'mk'], ['A', 'ab'], ['A', 'twice'], ['A', 'thrice'],
          ['A', '__construct'], ['HasC', 'm'], ['A', 'm']] as [$c, $m]) { show($c, $m); }

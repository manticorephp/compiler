<?php
// ReflectionProperty setValue/getValue across visibilities, statics, readonly, typed errors and throwables
class A { protected int $p = 1; private string $s = 'x'; public static int $n = 5; public readonly int $r; function __construct() { $this->r = 9; } function dump() { return $this->p . $this->s; } }
$a = new A;
$rp = new ReflectionProperty('A', 'p'); $rp->setValue($a, 7);
$rs = new ReflectionProperty('A', 's'); $rs->setValue($a, 'y');
echo $a->dump(), ' ', $rp->getValue($a), $rs->getValue($a), "\n";
$rn = new ReflectionProperty('A', 'n'); $rn->setValue(null, 8); echo A::$n, $rn->getValue(), "\n";
try { (new ReflectionProperty('A', 'r'))->setValue($a, 1); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$e = new RuntimeException('m', 1);
$prev = new LogicException('inner');
foreach (['message' => 'new', 'code' => 5, 'previous' => $prev, 'line' => 77, 'file' => 'f.php'] as $k => $v) {
    (new ReflectionProperty('Exception', $k))->setValue($e, $v);
}
echo $e->getMessage(), $e->getCode(), $e->getPrevious()->getMessage(), $e->getLine(), $e->getFile(), "\n";
$t = new TypeError('te');
(new ReflectionProperty('Error', 'message'))->setValue($t, 'tt');
echo $t->getMessage(), "\n";
echo (new ReflectionProperty('Exception', 'message'))->getValue($e), "\n";

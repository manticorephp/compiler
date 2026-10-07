<?php
class T extends SplFixedArray {
    public int $own = 3;
    public function offsetGet($i): mixed { return 'o:' . parent::offsetGet($i); }
}
$t = new T(2); $t[0] = 'v';
echo $t[0], ' ', $t->own, ' ', count($t), "\n";

class Tokens extends SplFixedArray {
    public array $log = [];
    public string $name = 'tok';
    public function __construct(int $n) { parent::__construct($n); $this->log[] = 'ctor'; }
    public function __clone() { $this->log[] = 'cloned'; foreach ($this as $k => $v) { if (is_object($v)) { $this[$k] = clone $v; } } }
    public function __destruct() { echo "tokens gone ", $this->name, "\n"; }
}
class Tok { public function __construct(public string $s) {} public function __destruct() { echo "tok ", $this->s, "\n"; } }
$a = new Tokens(2); $a[0] = new Tok('x'); $a[1] = 'plain';
$b = clone $a; $b->name = 'copy';
$b[0]->s = 'x2';
echo $a[0]->s, ' ', $b[0]->s, ' ', $a[1], ' ', $b[1], ' ', json_encode($b->log), "\n";
$b[1] = 'changed';
echo $a[1], ' ', $b[1], "\n";
unset($b);
echo "b gone\n";
unset($a);
echo "a gone\n";

class NoParentCtor extends SplFixedArray { public function __construct() {} }
$n = new NoParentCtor();
echo count($n), "\n";
try { $n[0] = 1; } catch (\Throwable $ex) { echo get_class($ex), ": ", $ex->getMessage(), "\n"; }
function total(SplFixedArray $s): int { $x = 0; foreach ($s as $v) { $x += (int)$v; } return $x; }
echo total(SplFixedArray::fromArray([1, 2, 3])), ' ', total($t), "\n";

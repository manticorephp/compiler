<?php
// Every write path initializes a typed property; a read before any write throws
class A { public array $a; public int $i; public ?int $n; public mixed $m; public string $s; public ?A $next;
  public function __construct() {} 
  public function setI(int $v): void { $this->i = $v; }
  public function getI(): int { return $this->i; }
}
class B extends A { public float $f; public int $own = 7; }
function t(callable $f): void { try { $f(); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; } }
$o = new A;
$o->a[] = 1; $o->a['k'] = 2; echo count($o->a), "\n";
t(function () use ($o) { echo $o->i, "\n"; });
$o->setI(5); echo $o->getI(), "\n";
t(function () use ($o) { $o->i++; });
echo $o->i, "\n";
t(function () use ($o) { echo $o->s . "x"; });
$o->s = "a"; $o->s .= "b"; echo $o->s, "\n";
t(function () use ($o) { var_dump($o->n); });
$r = &$o->n; $r = 3; var_dump($o->n);
t(function () use ($o) { var_dump($o->next?->i); });
$b = new B; t(function () use ($b) { echo $b->f; }); $b->f = 2.5; echo $b->f, " ", $b->own, "\n";
t(function () use ($b) { echo $b->i; });
$x = new B; $x->i = 9; $c = clone $x; echo $c->i, "\n"; t(function () use ($c) { echo $c->s; });
function mod(array &$a) { $a[] = 5; }
$p = new A; $p->a = []; mod($p->a); echo count($p->a), "\n";
preg_match('/(b)/', 'abc', $p->m); var_dump($p->m);

$mx = new A; $mx->m = null; var_dump($mx->m);

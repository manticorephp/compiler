<?php
// A constructor that definitely assigns a typed property skips its check; a subclass ctor that does not keeps it
class P { public int $a; public string $b; public int $late;
  public function __construct(int $a) { $this->a = $a; $this->b = "b" . $a; $this->init(); }
  public function init(): void {}
}
class C1 extends P { public int $c; public function __construct() { parent::__construct(3); $this->c = 1; } }
class C2 extends P { public function __construct() { } }
class C3 extends P {}
function t(callable $f): void { try { $f(); } catch (Error $e) { echo $e->getMessage(), "\n"; } }
$p = new P(1); echo $p->a, $p->b, "\n"; t(fn() => $p->late);
$c1 = new C1; echo $c1->a, $c1->b, "\n"; t(fn() => $c1->c);
$c2 = new C2; t(fn() => $c2->a); t(fn() => $c2->b);
/** @param P $x */ function viaBase(P $x) { return $x->a; }
t(fn() => viaBase($c2));
$c3 = new C3(7); echo $c3->a, "\n";
var_dump($c2, $c1);
class Q { public int $x; public function __construct() { $this->x = $this->y(); } public function y(): int { return $this->x; } }
t(fn() => new Q);

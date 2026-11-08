<?php
class Clk { public function now(): DateTimeImmutable { return new DateTimeImmutable('2020-01-02 03:04:05 UTC'); } }
class Inner { public ?Inner $next = null; public int $n = 3; public function nx(): ?Inner { return $this->next; } public function num(): int { return $this->n; } }
class A { public function __construct(private ?Clk $clock = null, public ?Inner $inner = null) {}
  public function u() { return $this->clock?->now()->format('Y'); }
  public function v() { return $this->clock?->now()->format('Y') ?? 'dflt'; }
  public function w() { return $this->clock?->now()?->format('Y') ?? 'dflt2'; }
  public function p() { return $this->inner?->next->n; }
  public function q() { return $this->inner?->nx()->num(); }
}
$a = new A; $b = new A(new Clk, new Inner); $c = new A(null, (function () { $i = new Inner; $i->next = new Inner; return $i; })());
var_dump($a->u(), $b->u(), $a->v(), $b->v(), $a->w(), $b->w());
var_dump($a->p(), $c->p(), $a->q(), $c->q());

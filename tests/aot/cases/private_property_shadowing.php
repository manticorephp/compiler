<?php
// A private property redeclared in a subclass is a separate slot; the two classes share one slot instead.
class P { private array $r = []; private string $name = 'parent';
  public function add($x): void { $this->r[] = $x; }
  public function p(): string { return count($this->r) . ':' . $this->name; }
  public function reset(): void { $this->r = []; } }
class C extends P { private array $r = []; private string $name = 'child';
  public function add($x): void { $this->r[] = 'c' . $x; parent::add($x); }
  public function c(): string { return count($this->r) . ':' . $this->name; }
  public function resetChild(): void { $this->r = []; } }
$c = new C; $c->add(1); $c->add(2);
echo $c->c(), ' ', $c->p(), "\n";
$c->resetChild(); echo $c->c(), ' ', $c->p(), "\n";
$c->add(3); $c->reset(); echo $c->c(), ' ', $c->p(), "\n";

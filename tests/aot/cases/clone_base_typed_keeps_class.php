<?php
abstract class Base {
    protected int $h = 0;
    abstract protected function kind(): int;
    public function __construct(int $n = 0) { $this->h = $n + $this->kind(); }
    public function __clone() { $this->h = $this->h + 1000; }
    public static function make(int $n): static { $o = new static($n); return $o; }
    public function who(): string { return static::class . ':' . $this->kind() . ':' . $this->h; }
}
abstract class Mid extends Base {
    abstract protected function lo(): int;
    public function range(): string { return $this->check(); }
    private function check(): string { return static::class . ' lo=' . $this->lo(); }
}
final class A extends Mid { protected function kind(): int { return 1; } protected function lo(): int { return -128; } }
final class B extends Mid { protected function kind(): int { return 5; } protected function lo(): int { return 0; } }
$a = A::make(1);
$u = B::make(3);
echo $u->who(), ' ', $u->range(), "\n";
$c = clone $u;
echo $c->who(), ' ', $c->range(), "\n";
$d = clone $a;
echo $d->who(), ' ', $d->range(), "\n";
$n = new B(2); $m = clone $n;
echo $m->who(), ' ', $m->range(), "\n";

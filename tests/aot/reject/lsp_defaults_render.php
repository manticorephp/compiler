<?php
class A { public function f(?int $a = null, string $s = "a long string here", array $r = [], array $n = [1, 2], float $d = 1.5, int $c = PHP_INT_MAX, $k = self::X, int ...$more): static { return $this; } const X = 1; }
class B extends A { public function f(): void {} }

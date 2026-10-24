<?php
class A { public function f(int|string|null|array $a = null, A|Countable $b = null, iterable $i = []): bool|int { return 1; } }
class B extends A { public function f(): int|string { return 1; } }

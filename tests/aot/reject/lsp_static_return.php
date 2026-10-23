<?php
class A { public function f(): static { return new static; } }
class B extends A { public function f(): self { return new B; } }

<?php
class A { public function f(): self { return $this; } }
class B extends A { public function f(): A|int { return $this; } }

<?php
class A { public function f(): int { return 1; } }
class B extends A { public function f(): int|string { return 1; } }

<?php
class A { public function f(): int { return 1; } }
trait T { public function g() {} }
class B extends A { use T; public function f(): string { return ""; } }

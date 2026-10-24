<?php
class A { public function &f(): array { static $a = []; return $a; } }
class B extends A { public function f(): array { return []; } }

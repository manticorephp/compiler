<?php
class P {} class C extends P {}
class A { public function f(): C { return new C; } }
class B extends A { public function f(): P { return new P; } }

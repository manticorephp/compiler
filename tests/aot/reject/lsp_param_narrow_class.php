<?php
class P {} class C extends P {}
class A { public function f(P $x) {} }
class B extends A { public function f(C $x) {} }

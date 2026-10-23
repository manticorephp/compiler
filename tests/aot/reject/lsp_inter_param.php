<?php
interface I {} interface J {}
class A { public function f(I&J $x) {} }
class B extends A { public function f(I $x) {} public function g() {} }
class C extends A { public function f(I&J&Countable $x) {} }

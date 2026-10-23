<?php
class A { final public function f() { return 1; } }
class B extends A {
    public function f() { return 2; }
}

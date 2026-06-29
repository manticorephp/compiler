<?php
class A extends Exception { public int $v; public function __construct(int $v) { $this->v = $v; } }
class B extends Exception { public int $v; public function __construct(int $v) { $this->v = $v; } }
class C extends Exception { public int $v; public function __construct(int $v) { $this->v = $v; } }
try {
    throw new B(99);
} catch (A | B $e) {
    echo "A_or_B";
} catch (C $e) {
    echo "C";
}

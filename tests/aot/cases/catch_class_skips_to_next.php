<?php
class A extends Exception { public int $v; public function __construct(int $v) { $this->v = $v; } }
class B extends Exception { public int $v; public function __construct(int $v) { $this->v = $v; } }
try {
    throw new B(7);
} catch (A $e) {
    echo "A:", $e->v;
} catch (B $e) {
    echo "B:", $e->v;
}

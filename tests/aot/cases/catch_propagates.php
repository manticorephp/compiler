<?php
class Boom extends Exception { public int $code; public function __construct(int $c) { $this->code = $c; } }
class Splat extends Exception { public int $z;  public function __construct(int $z) { $this->z = $z; } }
try {
    try {
        throw new Boom(1);
    } catch (Splat $e) {
        echo "wrong-catch";
    }
} catch (Boom $e) {
    echo "right:", $e->code;
}

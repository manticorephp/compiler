<?php
class Boom extends Exception { public int $code; public function __construct(int $c) { $this->code = $c; } }
class Splat extends Exception { public int $z;  public function __construct(int $z) { $this->z = $z; } }

try {
    throw new Boom(42);
} catch (Splat $e) {
    echo "splat:", $e->z;
} catch (Boom $e) {
    echo "boom:", $e->code;
}

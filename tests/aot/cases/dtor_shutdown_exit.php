<?php
class D {
    public function __construct(public string $n) {}
    public function __destruct() { echo "dtor {$this->n}\n"; }
}
class K { public static array $all = []; }
function work() {
    K::$all[] = new D("a");
    K::$all[] = new D("b");
    exit(0);
}
work();

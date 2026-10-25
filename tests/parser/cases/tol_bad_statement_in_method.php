<?php
class A {
    public function ok1() { return 1; }
    public function bad() {
        $x = 1;
        foreach ($xs) { $q = 1; }
        $y = 2;
    }
    public function ok2() { return 2; }
}
$after = 3;

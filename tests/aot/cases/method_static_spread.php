<?php
class C {
    function add($a, $b, $c) { return $a + $b + $c; }
    static function s($a, $b) { return $a * $b; }
}
$o = new C();
$args = [1, 2, 3];
echo $o->add(...$args), "\n";
$p = [4, 5];
echo C::s(...$p), "\n";
$cb = [$o, 'add'];
echo call_user_func_array($cb, [10, 20, 30]), "\n";

<?php
function add(int $a, int $b) { return $a + $b; }
class M {
    public static function mul(int $a, int $b) { return $a * $b; }
    public static function run() {
        echo forward_static_call('add', 2, 3), "\n";
        echo forward_static_call_array('add', [4, 5]), "\n";
        echo forward_static_call(['M', 'mul'], 3, 4), "\n";
        echo forward_static_call_array('M::mul', [5, 6]), "\n";
        echo forward_static_call(fn(int $x) => $x * 2, 21), "\n";
    }
}
M::run();

<?php
class Counter {
    public static int $total = 0;
    public static function bump(int $n): int {
        Counter::$total = Counter::$total + $n;
        return Counter::$total;
    }
}
Counter::bump(3);
Counter::bump(7);
echo Counter::$total;

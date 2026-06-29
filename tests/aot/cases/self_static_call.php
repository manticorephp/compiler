<?php
class Counter {
    public static function step(int $x): int { return $x + 1; }
    public static function twice(int $x): int { return self::step(self::step($x)); }
}
echo Counter::twice(10);

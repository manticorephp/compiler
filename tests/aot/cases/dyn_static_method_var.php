<?php
// `$c::$m(args)` / `$c::$m(...)` with runtime class and method: private via self::, spread, closure, object receiver
final class Ip {
    private static function v4(string $a, string $b): bool { return $a === $b; }
    protected static function v6(string $a, string $b): bool { return false; }
    public static function check(string $a, string $b): bool
    {
        $method = str_contains($a, ':') ? 'v6' : 'v4';
        return self::$method($a, $b);
    }
    public static function sum(int ...$n): int { return array_sum($n); }
}
var_dump(Ip::check('1', '1'), Ip::check('::1', '::1'));
$c = 'Ip'; $m = 'sum'; $args = [1, 2, 3];
var_dump($c::$m(...$args), $c::$m(4, 5));
$f = $c::$m(...);
var_dump($f(7, 8), $f instanceof Closure);
$o = new Ip(); var_dump($o::$m(1));

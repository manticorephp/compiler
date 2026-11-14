<?php
// $x instanceof self::$prop (class name held in a static property) fails to parse.
class Engine {}
class BigInteger {
    private static $mainEngine = Engine::class;
    public static function check($x): bool { return $x instanceof self::$mainEngine; }
}
var_dump(BigInteger::check(new Engine), BigInteger::check(new stdClass));
class Eng2 extends Engine {}
class B { public static $m = ['a' => Engine::class]; static function c($x) { return [$x instanceof self::$m['a'], $x instanceof static::$m['a']]; } }
var_dump(B::c(new Eng2), B::c(new stdClass));

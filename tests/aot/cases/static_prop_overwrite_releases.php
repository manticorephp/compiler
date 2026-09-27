<?php
final class T { public static int $d = 0; public function __construct(public array $toks) {} public function __destruct() { self::$d++; } }
final class C {
    /** @var array<string, T> */
    private static array $cache = [];
    public static ?T $one = null;
    public static $any = null;
    public static string $s = '';
    public static function get(string $k): T { if (!isset(self::$cache[$k])) { self::$cache[$k] = new T([1, 2, 3]); } return self::$cache[$k]; }
    public static function clear(?string $k = null): void { if ($k === null) { self::$cache = []; return; } unset(self::$cache[$k]); }
}
for ($i = 0; $i < 3; $i++) { C::get("a$i"); C::get("b$i"); C::clear(); }
echo "after clear-all: ", T::$d, "\n";
for ($i = 0; $i < 3; $i++) { C::get("x$i"); C::clear("x$i"); }
echo "after unset: ", T::$d, "\n";
for ($i = 0; $i < 3; $i++) { C::$one = new T([$i]); }
echo "typed obj: ", T::$d, "\n";
for ($i = 0; $i < 3; $i++) { C::$any = new T([$i]); }
C::$any = 5;
echo "untyped: ", T::$d, "\n";
$t = new T([9]);
C::$one = $t; C::$one = $t; C::$one = null;
echo "self-assign alive: ", T::$d, " ", count($t->toks), "\n";
for ($i = 0; $i < 3; $i++) { C::$s = str_repeat("z", $i + 40); }
echo C::$s, "\n";

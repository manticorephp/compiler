<?php
// With a __call class in the program, bindTo(null) on a static closure read back from a static array yields a closure that returns 0
class Magic { public function __call($n, $a) { return "magic:$n"; } }
final class Reg {
    private static array $h = [];
    public static function get(string $k): mixed { return self::$h[$k] ??= static function (int $x) { return $x * 2; }; }
}
$b = Reg::get('a')->bindTo(null);
echo $b(6), "\n";

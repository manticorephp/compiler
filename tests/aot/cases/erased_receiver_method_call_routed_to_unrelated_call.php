<?php
// A method call on a value of unknown type (closure, null) is dispatched to an unrelated class's __call instead of the closure method / an Error
class Plain {}
class Magic { public function __call($n, $a) { return "magic:$n"; } }
final class Reg {
    private static array $h = [];
    public static function get(string $k): mixed { return self::$h[$k] ??= function (int $x = 1) { return $x * 2; }; }
}
$f = Reg::get('a');
echo $f->__invoke(4), "\n";
echo $f->call(new Plain, 5), "\n";
try { $f->nosuch(); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$o = [1, 'x' => null]['x'];
try { $o->foo(); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo (new Magic)->anything(), "\n";

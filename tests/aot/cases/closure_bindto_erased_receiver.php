<?php
// bindTo() on a closure read back from a static-prop array element dispatches into an unrelated __call
final class Proxy { public function __call($n, $a) { return $a[0]->payload; } }
final class H {
    private static $hydrators = [];
    public static function get(string $class) {
        $base = self::$hydrators['std'] ??= static function ($props, &$objects) {
            foreach ($props as $name => $values) {
                foreach ($values as $i => $v) { $objects[$i]->$name = $v; }
            }
        };
        return $base->bindTo(null, $class);
    }
}
class P { private $x = 1; function x() { return $this->x; } }
$h = H::get('P');
$o = new P;
$objs = [$o, 'pad'];
$h(['x' => [0 => 42]], $objs);
echo $o->x(), "\n";

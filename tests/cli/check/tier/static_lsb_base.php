<?php
class Base5 {
    public static function mk() { return static::hook(); }
    public static function hook() { return missing_base_hook(); }
}
class Sub5 extends Base5 { public static function hook() { return 5; } }
class Leaf6 extends Base5 { public static function hook() { return missing_leaf_hook(); } }
echo Sub5::mk(), "\n";
echo (new Leaf6())::class, "\n";

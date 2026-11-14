<?php
// static::$prop = v where only subclasses declare the property fails to lower: "unsupported assign target kind StaticAccess".
abstract class Key {
    public static function forceEngine($e) { static::$forcedEngine = $e; }
    public static function engine() { return static::$forcedEngine; }
}
class RSA extends Key { protected static $forcedEngine = null; }
RSA::forceEngine('PHP');
echo RSA::engine(), "\n";
try { Key::forceEngine('x'); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { Key::engine(); } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }

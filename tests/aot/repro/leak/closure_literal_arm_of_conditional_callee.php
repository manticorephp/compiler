<?php
// A closure literal in an arm of a conditional callee is never released: ($f ?? function () {...})() keeps the env and its captures alive.
// issue: #590
class H { public function __destruct() { echo "drop\n"; } }
class F {
    public static ?Closure $f = null;
    public static function run() { $k = new H(); return (static::$f ?? function () use ($k) { return 1; })(); }
}
F::run();
echo "end\n";

<?php
// `$c::$m()` and `[$c, $m]()` resolve through a tree of sorted names: every class of a group
// reachable, names that sort differently with and without case folding, a method only the
// parent declares, and names nothing has.
namespace App\Zeta { class Leaf { public static function run($x = 'd') { return 'Leaf::run ' . $x; } } }
namespace {
class Zebra { public static function run($x = 'd') { return 'Zebra::run ' . $x; } public static function Alpha() { return 'Zebra::Alpha'; } public static function beta_x() { return 'Zebra::beta_x'; } public static function BetaX() { return 'Zebra::BetaX'; } public static function zed() { return 'Zebra::zed'; } }
class apple { public static function run($x = 'd') { return 'apple::run ' . $x; } }
class Foo_Bar { public static function run($x = 'd') { return 'Foo_Bar::run ' . $x; } }
class FooBar { public static function run($x = 'd') { return 'FooBar::run ' . $x; } }
class FOOBAZ extends FooBar { public static function own() { return 'FOOBAZ::own'; } }
class Mid { public static function run($x = 'd') { return 'Mid::run ' . $x; } }

function call(string $c, string $m, array $a = []) {
    try { return $c::$m(...$a); } catch (Error $e) { return get_class($e) . ': ' . $e->getMessage(); }
}
foreach (['Zebra', 'zebra', 'apple', 'APPLE', 'Foo_Bar', 'foo_bar', 'FooBar', 'FOOBAR', 'FOOBAZ', 'foobaz', 'Mid', 'App\Zeta\Leaf', 'app\zeta\LEAF'] as $c) {
    echo $c, ' => ', call($c, 'run', ['v']), ' / ', call($c, 'RUN'), "\n";
}
foreach (['Alpha', 'alpha', 'beta_x', 'BETA_X', 'BetaX', 'betax', 'zed', 'ZED', 'nope'] as $m) { echo $m, ' => ', call('Zebra', $m), "\n"; }
echo call('FOOBAZ', 'own'), ' ', call('foobaz', 'OWN'), "\n";
echo call('Mid', 'own'), "\n";
}

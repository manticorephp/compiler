<?php
// method_exists / property_exists / is_a with RUNTIME names: case folding, inheritance, misses, many classes.
interface Marker {}
abstract class Base { public $pub = 1; protected $prot = 2; public function baseMethod() {} private function hidden() {} }
class Alpha extends Base implements Marker { public $own = 1; public function Run() {} public static function make() {} }
class Beta extends Alpha { public $extra = 2; public function other() {} }
class Zed { public function __invoke() {} }
class aaa { public function a() {} }
class AAb { public function b() {} }
$classes = ['Alpha', 'alpha', 'ALPHA', 'Beta', 'BETA', 'Zed', 'zed', 'Base', 'Marker', 'aaa', 'AAb', 'aab', 'Nope', ''];
$methods = ['run', 'RUN', 'Run', 'make', 'baseMethod', 'BASEMETHOD', 'other', '__invoke', 'a', 'b', 'missing', ''];
$props = ['pub', 'PUB', 'prot', 'own', 'extra', 'Own', 'nope'];
foreach ($classes as $c) {
    $row = '';
    foreach ($methods as $m) { $row .= method_exists($c, $m) ? '1' : '0'; }
    $row .= ' ';
    foreach ($props as $p) { $row .= property_exists($c, $p) ? '1' : '0'; }
    echo str_pad($c, 6), $row, "\n";
}
$objs = [new Alpha, new Beta, new Zed, new aaa];
foreach ($objs as $o) {
    $row = '';
    foreach ($methods as $m) { $row .= method_exists($o, $m) ? '1' : '0'; }
    foreach ($classes as $c) { $row .= is_a($o, $c) ? '1' : '0'; }
    foreach ($classes as $c) { $row .= is_a($o, $c, false) ? '1' : '0'; }
    foreach ($classes as $c) { $row .= is_subclass_of($o, $c) ? '1' : '0'; }
    echo get_class($o), ' ', $row, "\n";
}
foreach (['Beta', 'Alpha', 'Nope'] as $c) { echo $c, ':', is_a($c, 'Base', true) ? 1 : 0, is_subclass_of($c, 'Alpha') ? 1 : 0, "\n"; }

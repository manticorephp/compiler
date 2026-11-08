<?php
// method_exists, property_exists and is_subclass_of over runtime names: first and last class of
// the table, a class with no match, the same name asked with different case, in a loop so a
// remembered class is asked again, and a name freed and rebuilt at the same address.
class Aaa { public $x; public function first() {} public function Mid() {} }
class Mmm extends Aaa { public $y; public function zed() {} }
class Zzz { public $z; public function only() {} }
function ask(string $c, string $m) { return (method_exists($c, $m) ? 'y' : 'n') . (property_exists($c, $m) ? 'y' : 'n'); }
foreach ([1, 2] as $round) {
    foreach (['Aaa', 'aaa', 'AAA', 'Mmm', 'mMm', 'Zzz', 'zzz', 'Nope', 'Aa', 'Zzzz', ''] as $c) {
        foreach (['first', 'FIRST', 'mid', 'zed', 'only', 'x', 'y', 'z', 'none', ''] as $m) { echo ask($c, $m), ' '; }
        echo $c, "\n";
    }
}
for ($i = 0; $i < 6; $i++) {
    $name = ['Aaa', 'Zzz', 'Mmm'][$i % 3];
    $built = str_repeat(substr($name, 0, 1), 1) . substr($name, 1);
    echo $built, ' ', ask($built, 'only'), ' ', ask($built, 'zed'), ' ', is_subclass_of($built, 'Aaa') ? 'sub' : 'no', ' ', is_a($built, 'Aaa', true) ? 'isa' : 'no', "\n";
    unset($built);
}
$o = new Mmm;
foreach (['first', 'zed', 'only', 'ZED'] as $m) { echo method_exists($o, $m) ? 'y' : 'n'; }
echo "\n";

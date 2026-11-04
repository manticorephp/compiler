<?php
trait T1 { public function hi() { return 'hi'; } }
if (!method_exists('ArrayObject', 'nope')) { trait T2 { public function a() { return 1; } } } else { trait T2 { public function a() { return 2; } } }
class K { use T1; use T2; }
echo implode(',', (new ReflectionClass('K'))->getTraitNames()), "\n";
foreach (['T1', 'T2'] as $t) {
    $x = new ReflectionClass($t);
    echo $t, ' ', var_export($x->isTrait(), true), ' ', var_export(trait_exists($t), true), "\n";
}

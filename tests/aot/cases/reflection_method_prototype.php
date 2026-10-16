<?php
// ReflectionMethod::hasPrototype()/getPrototype() on methods that have none (a method nobody overrides, a private one, a root constructor).
abstract class Animal
{
    public function __construct() {}
    private function secret(): string { return 's'; }
}
class Dog extends Animal
{
    public function fetch(): string { return 'fetch'; }
    private function secret(): string { return 'd'; }
}

foreach (['Dog' => ['fetch', 'secret'], 'Animal' => ['__construct']] as $c => $ms) {
    foreach ($ms as $m) {
        echo $c, '::', $m, ' ', var_export((new ReflectionMethod($c, $m))->hasPrototype(), true), "\n";
    }
}
try { (new ReflectionMethod('Dog', 'fetch'))->getPrototype(); }
catch (ReflectionException $e) { echo $e->getMessage(), "\n"; }

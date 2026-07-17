<?php
abstract class Animal { public string $name = "x"; public function speak(): string { return "?"; } }
final class Dog extends Animal {
    public readonly int $age;
    public function __construct() { $this->age = 3; }
    public function speak(): string { return "woof"; }
}
$r = new ReflectionClass("Dog");
echo $r->getName(), "\n";
echo $r->name, "\n";
var_dump($r->isFinal());
var_dump($r->isAbstract());
var_dump($r->hasMethod("speak"));
var_dump($r->hasProperty("age"));
var_dump($r->hasProperty("nope"));
echo $r->getParentClass()->getName(), "\n";
var_dump($r->isSubclassOf("Animal"));
$o = new Dog();
$r2 = new ReflectionClass($o);
echo $r2->getName(), "\n";
$a = new ReflectionClass("Animal");
var_dump($a->isAbstract());
var_dump($a->getParentClass());
var_dump($a->isInstantiable());
try { new ReflectionClass("Nope"); } catch (ReflectionException $e) { echo $e->getMessage(), "\n"; }

<?php
// Reflection Tier-1: compile-time class queries (no runtime metadata).
interface Speaker {
    public function speak(): string;
}

trait Wagging {
    public function wag(): string { return "wag"; }
}

class Animal {
    public string $name;
    public function getName(): string { return $this->name; }
    public function setName(string $n): void { $this->name = $n; }
}

class Dog extends Animal implements Speaker {
    public int $legs;
    public function speak(): string { return "woof"; }
}

$d = new Dog();

var_dump(class_exists("Dog"));
var_dump(class_exists("Cat"));
var_dump(class_exists("Speaker"));
var_dump(interface_exists("Speaker"));
var_dump(interface_exists("Dog"));
var_dump(trait_exists("Wagging"));
var_dump(trait_exists("Speaker"));

var_dump(method_exists($d, "speak"));
var_dump(method_exists($d, "getName"));
var_dump(method_exists($d, "fly"));

var_dump(property_exists($d, "legs"));
var_dump(property_exists($d, "name"));
var_dump(property_exists($d, "wings"));

var_dump(is_a($d, "Animal"));
var_dump(is_a($d, "Speaker"));
var_dump(is_a($d, "Dog"));

var_dump(is_subclass_of($d, "Animal"));
var_dump(is_subclass_of($d, "Dog"));
var_dump(is_subclass_of($d, "Speaker"));

echo get_parent_class($d), "\n";
var_dump(get_parent_class(new Animal()));

$methods = get_class_methods($d);
echo implode(",", $methods), "\n";

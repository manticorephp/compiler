<?php
class Animal {}
class Dog extends Animal {}
class Box { public string $cls = 'Dog'; }
$b = new Box();
$d = new Dog();
$a = new Animal();
var_dump($d instanceof $b->cls);
var_dump($a instanceof $b->cls);
$names = ['Animal', 'Dog'];
var_dump($d instanceof $names[0]);
var_dump($a instanceof $names[1]);
